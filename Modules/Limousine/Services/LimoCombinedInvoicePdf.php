<?php

declare(strict_types=1);

namespace Modules\Limousine\Services;

use App\Erp\Settings\Setting;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoInvoice;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Support\ImportedLegCopies;
use Modules\Limousine\Support\LegacyInvoiceBookings;

/**
 * Many bills as one document: a month of work on a single page.
 *
 * A company that ran forty trips does not want forty PDFs — it wants one
 * schedule it can check line by line and pay against. So the row here is a
 * TRIP, not an invoice: the columns a customer reconciles against are the
 * journey's (who travelled, which car, where to, when), and an invoice number
 * tells them nothing about the day their guest was collected.
 *
 * The invoices covered are named in the header, so the document still ties back
 * to what was billed.
 */
final class LimoCombinedInvoicePdf
{
    /**
     * @param  Collection<int, LimoInvoice>  $invoices
     * @return array<string, mixed>
     */
    public function viewData(Collection $invoices, ?string $from = null, ?string $to = null): array
    {
        [$rows, $tripDays] = $this->build($invoices);

        $total = round((float) $invoices->sum('total'), 3);
        $paid = round((float) $invoices->sum('amount_paid'), 3);

        // The period is the work's, not the paper's: the window asked for,
        // else from the first trip to the last.
        $dates = $invoices->map(static fn (LimoInvoice $i): ?\Illuminate\Support\Carbon => $i->service_date ?? $i->issue_date)
            ->filter()->sort()->values();

        return [
            'customer' => $invoices->first()?->customer,
            'customerVat' => trim((string) ($invoices->first()?->customer->vat_number ?? '')),
            'rows' => $rows,
            'references' => $invoices->pluck('reference')->filter()->values()->all(),
            ...$this->period($this->day($from), $this->day($to), $tripDays, $dates),
            'total' => $total,
            'paid' => $paid,
            'balance' => round($total - $paid, 3),
            'companyName' => (string) Setting::get('company.name', 'OpenERP'),
            'companyPhone' => (string) Setting::get('company.phone', ''),
            'companyEmail' => (string) Setting::get('company.email', ''),
            'logoPath' => $this->logoPath(),
            'logoScale' => $this->logoScale(),
        ];
    }

    /**
     * The document's lines on their own: one per trip, plus a line for a bill
     * with no trips and one for any difference between the trips and the bill.
     * The invoices list's Excel / CSV / Print are built from these, so a sheet
     * has exactly the rows and columns the printed invoice has.
     *
     * Each line also carries its `invoice` number and `customer`, which the
     * document prints once in its header instead.
     *
     * @param  Collection<int, LimoInvoice>  $invoices
     * @return list<array<string, mixed>>
     */
    public function rows(Collection $invoices): array
    {
        return $this->build($invoices)[0];
    }

    /**
     * @param  Collection<int, LimoInvoice>  $invoices
     * @return array{0: list<array<string, mixed>>, 1: list<\Illuminate\Support\Carbon>}
     */
    private function build(Collection $invoices): array
    {
        $invoices->loadMissing(['booking.legs', 'customer']);

        // An invoice brought over from the old system that could not be tied
        // to one booking names its bookings in its notes; fetch them all at
        // once so their trips print like any other.
        $legacyIds = $invoices
            ->filter(static fn (LimoInvoice $invoice): bool => $invoice->booking === null)
            ->flatMap(static fn (LimoInvoice $invoice): array => LegacyInvoiceBookings::ids($invoice->notes))
            ->unique()->values()->all();
        $legacy = $legacyIds === []
            ? collect()
            : LimoBooking::query()->with('legs')->whereKey($legacyIds)->get()->keyBy('id');

        $rows = [];
        $serial = 0;
        /** @var list<\Illuminate\Support\Carbon> $tripDays */
        $tripDays = [];

        foreach ($invoices as $invoice) {
            /** @var list<LimoBooking> $bookings */
            $bookings = $invoice->booking !== null
                ? [$invoice->booking]
                : array_values(array_filter(array_map(
                    // The same customer's bookings only: a number cut short
                    // in the old export can name someone else's trip.
                    static fn (int $id): ?LimoBooking => ($b = $legacy->get($id)) !== null && $b->customer_id === $invoice->customer_id ? $b : null,
                    LegacyInvoiceBookings::ids($invoice->notes),
                )));
            $start = count($rows);
            $legCount = 0;
            $legTotal = 0.0;

            foreach ($bookings as $booking) {
                $printedTrip = false;
                foreach ($this->distinctLegs($booking) as $leg) {
                    $serial++;
                    $legCount++;
                    $legTotal = round($legTotal + (float) $leg->net_amount, 3);
                    if ($leg->start_at !== null) {
                        $tripDays[] = $leg->start_at->copy()->startOfDay();
                    }

                    $rows[] = [
                        'serial' => $serial,
                        'booking' => (string) ($booking->reference ?? ''),
                        'trip' => (string) ($leg->reference ?? ''),
                        'service' => $this->service($leg),
                        'vehicle' => $leg->billedVehicle($booking),
                        'from' => (string) ($leg->from_location ?? ''),
                        'to' => (string) ($leg->to_location ?? ''),
                        'date' => $leg->start_at?->isoFormat('DD-MMM-YY HH:mm') ?? '',
                        // The customer's OWN reference for the job — what they look
                        // it up by in their system, not ours.
                        'company_reference' => (string) ($booking->company_reference ?? ''),
                        // A private customer is usually the one travelling.
                        'pax' => $this->pax($booking, $invoice),
                        // The trip itself; its extras print as their own lines under it.
                        'total' => round((float) $leg->net_amount - (float) $leg->extras_total, 3),
                        'extras' => $leg->extraLines(),
                    ];
                    $printedTrip = true;
                }

                // Meet & greet, once per booking, under its trips: charged only
                // while the booking has a trip on the bill.
                if ($printedTrip && $booking->meetGreetCharge() > 0.0) {
                    $serial++;
                    $legTotal = round($legTotal + $booking->meetGreetCharge(), 3);
                    $rows[] = [
                        'serial' => $serial,
                        'booking' => (string) ($booking->reference ?? ''),
                        'trip' => '',
                        'service' => (string) __('Meet & greet'),
                        'vehicle' => '',
                        'from' => trim((string) $booking->meet_greet_name),
                        'to' => '', 'date' => '',
                        'company_reference' => (string) ($booking->company_reference ?? ''),
                        'pax' => $this->pax($booking, $invoice),
                        'total' => $booking->meetGreetCharge(),
                        'extras' => [],
                    ];
                }
            }

            // A bill with no journeys behind it — a late fee, or one raised from
            // a quote and not yet dispatched. It is still money owed, so it gets
            // its own line rather than vanishing from a document that has to add
            // up to what is being asked for.
            if ($legCount === 0) {
                $serial++;
                $rows[] = [
                    'serial' => $serial,
                    'booking' => '', 'trip' => '',
                    // Named, so the reader can find the invoice: one with no
                    // booking behind it is linked from its own page.
                    'service' => ($invoice->charge_label ?: __('Limousine services')) . ' (' . $invoice->reference . ')',
                    'vehicle' => '', 'from' => '', 'to' => '',
                    'date' => $invoice->issue_date?->isoFormat('DD-MMM-YY') ?? '',
                    'company_reference' => '', 'pax' => '',
                    'total' => round((float) $invoice->total, 3),
                ];
                $this->tag($rows, $start, $invoice);

                continue;
            }

            // Where the trips do not sum to what was billed — a discount, or a
            // bill frozen by a payment while the job was later re-priced — the
            // difference is stated rather than left for the reader to find.
            $difference = round((float) $invoice->total - $legTotal, 3);
            if (abs($difference) > 0.0005) {
                $serial++;
                $rows[] = [
                    'serial' => $serial,
                    'booking' => count($bookings) === 1 ? (string) ($bookings[0]->reference ?? '') : '',
                    'trip' => '',
                    'service' => $difference < 0
                        ? __('Discount on :reference', ['reference' => $invoice->reference])
                        : __('Adjustment on :reference', ['reference' => $invoice->reference]),
                    'vehicle' => '', 'from' => '', 'to' => '', 'date' => '',
                    'company_reference' => '', 'pax' => '',
                    'total' => $difference,
                ];
            }

            $this->tag($rows, $start, $invoice);
        }

        return [$rows, $tripDays];
    }

    /**
     * Stamp the lines just added for one invoice with that invoice's number
     * and customer.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function tag(array &$rows, int $start, LimoInvoice $invoice): void
    {
        for ($i = $start, $n = count($rows); $i < $n; $i++) {
            $rows[$i]['invoice'] = (string) ($invoice->reference ?? '');
            $rows[$i]['customer'] = (string) ($invoice->customer->name ?? '');
        }
    }

    private function pax(LimoBooking $booking, LimoInvoice $invoice): string
    {
        $pax = trim((string) ($booking->pax_name ?? ''));
        // "." or "-" typed to get past the form is not a name.
        if (preg_match('/[\p{L}\p{N}]/u', $pax) === 1) {
            return $pax;
        }

        $customer = $invoice->customer;

        return $customer !== null && ! $customer->isCompany() ? (string) $customer->name : '';
    }

    /**
     * The trips on the bill: a cancelled one is left out (unless its money
     * was kept), exactly as the fare leaves it out. Rows the import COPIED
     * are left out too, and only those: several cars sent out together look
     * identical and are each a journey. See {@see ImportedLegCopies}.
     *
     * This mattered more here than anywhere. When look-alikes were dropped by
     * likeness alone, the money they carried resurfaced below as an
     * "Adjustment" line, so the missing trips were disguised as a correction
     * rather than showing as journeys.
     *
     * @return \Illuminate\Support\Collection<int, LimoLeg>
     */
    private function distinctLegs(LimoBooking $booking): \Illuminate\Support\Collection
    {
        return ImportedLegCopies::without(
            $booking->legs->filter(static fn (LimoLeg $leg): bool => $leg->isBillable()),
            $booking->legsFare(),
        );
    }

    /**
     * The window asked for — but only when every trip printed falls inside
     * it. Invoices ticked under another month, or a bill whose trips run on
     * past the month end, get the real first-to-last trip dates instead: a
     * Period that disagrees with the rows below it is worse than none.
     *
     * @param  list<\Illuminate\Support\Carbon>  $tripDays
     * @param  \Illuminate\Support\Collection<int, \Illuminate\Support\Carbon>  $invoiceDays
     * @return array{periodFrom: ?\Illuminate\Support\Carbon, periodTo: ?\Illuminate\Support\Carbon}
     */
    private function period(?\Illuminate\Support\Carbon $from, ?\Illuminate\Support\Carbon $to, array $tripDays, \Illuminate\Support\Collection $invoiceDays): array
    {
        $days = collect($tripDays)->sort()->values();
        if ($days->isEmpty()) {
            $days = $invoiceDays;
        }

        $inside = $days->every(static fn (\Illuminate\Support\Carbon $d): bool => ($from === null || $d->gte($from)) && ($to === null || $d->lte($to)));

        return [
            'periodFrom' => $inside && $from !== null ? $from : $days->first(),
            'periodTo' => $inside && $to !== null ? $to : $days->last(),
        ];
    }

    private function day(?string $value): ?\Illuminate\Support\Carbon
    {
        if ($value === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        return \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $value)?->startOfDay();
    }

    private function service(LimoLeg $leg): string
    {
        return __(ucfirst(str_replace('_', ' ', (string) $leg->service_type)));
    }

    /** @param array<string, mixed> $data */
    public function render(array $data): string
    {
        // Landscape: nine columns of trip detail do not fit a portrait page
        // without shrinking the type past reading size.
        return Pdf::loadView('limousine::combined-invoice-pdf', $data)
            ->setPaper('a4', 'landscape')
            ->output();
    }

    /** @param Collection<int, LimoInvoice> $invoices */
    public function filename(Collection $invoices): string
    {
        $name = (string) ($invoices->first()?->customer->name ?? 'customer');

        return 'tax-invoice-' . str_replace([' ', '/', '\\'], '-', $name) . '.pdf';
    }

    private function logoScale(): int
    {
        $raw = (int) Setting::get('company.logo_scale', 100);

        return max(50, min(400, $raw > 0 ? $raw : 100));
    }

    private function logoPath(): ?string
    {
        $rel = Setting::get('company.logo');
        if (! is_string($rel) || $rel === '') {
            return null;
        }

        $disk = Storage::disk('public');

        return $disk->exists($rel) ? $disk->path($rel) : null;
    }
}
