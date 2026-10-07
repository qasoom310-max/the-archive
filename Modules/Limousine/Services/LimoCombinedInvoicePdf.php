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
            $legCount = 0;
            $legTotal = 0.0;

            foreach ($bookings as $booking) {
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
                        'total' => round((float) $leg->net_amount, 3),
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
                    'booking' => '',
                    'service' => $invoice->charge_label ?: __('Limousine services'),
                    'vehicle' => '', 'from' => '', 'to' => '', 'date' => '',
                    'company_reference' => '', 'pax' => '',
                    'total' => round((float) $invoice->total, 3),
                ];

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
                    'service' => $difference < 0
                        ? __('Discount on :reference', ['reference' => $invoice->reference])
                        : __('Adjustment on :reference', ['reference' => $invoice->reference]),
                    'vehicle' => '', 'from' => '', 'to' => '', 'date' => '',
                    'company_reference' => '', 'pax' => '',
                    'total' => $difference,
                ];
            }
        }

        $total = round((float) $invoices->sum('total'), 3);
        $paid = round((float) $invoices->sum('amount_paid'), 3);

        // The period is the work's, not the paper's: the window asked for,
        // else from the first trip to the last.
        $dates = $invoices->map(static fn (LimoInvoice $i): ?\Illuminate\Support\Carbon => $i->service_date ?? $i->issue_date)
            ->filter()->sort()->values();

        return [
            'customer' => $invoices->first()?->customer,
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
     * was kept), exactly as the fare leaves it out. The import that carried
     * old bookings over also duplicated a handful of legs byte for byte;
     * printing one journey twice reads as a double charge.
     *
     * @return \Illuminate\Support\Collection<int, LimoLeg>
     */
    private function distinctLegs(LimoBooking $booking): \Illuminate\Support\Collection
    {
        return $booking->legs->filter(static fn (LimoLeg $leg): bool => $leg->isBillable())->unique(static fn (LimoLeg $leg): string => implode('|', [
            $leg->service_type,
            (string) $leg->vehicle_details,
            (string) $leg->from_location,
            (string) $leg->to_location,
            (string) $leg->start_at,
            (string) $leg->net_amount,
        ]))->values();
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

        return 'invoice-' . str_replace([' ', '/', '\\'], '-', $name) . '.pdf';
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
