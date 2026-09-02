<?php

declare(strict_types=1);

namespace Modules\Limousine\Services;

use App\Erp\Settings\Setting;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use Modules\Limousine\Models\LimoInvoice;
use Modules\Limousine\Models\LimoLeg;

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
    public function viewData(Collection $invoices): array
    {
        $invoices->loadMissing(['booking.legs', 'customer']);

        $rows = [];
        $serial = 0;

        foreach ($invoices as $invoice) {
            $legs = $invoice->booking->legs ?? collect();
            $legTotal = 0.0;

            foreach ($legs as $leg) {
                $serial++;
                $legTotal = round($legTotal + (float) $leg->net_amount, 3);

                $rows[] = [
                    'serial' => $serial,
                    'booking' => (string) ($invoice->booking->reference ?? ''),
                    'service' => $this->service($leg),
                    'vehicle' => (string) ($leg->vehicle ?? ''),
                    'from' => (string) ($leg->from_location ?? ''),
                    'to' => (string) ($leg->to_location ?? ''),
                    'date' => $leg->start_at?->isoFormat('DD-MMM-YY HH:mm') ?? '',
                    // The customer's OWN reference for the job — what they look
                    // it up by in their system, not ours.
                    'company_reference' => (string) ($invoice->booking->company_reference ?? ''),
                    'pax' => (string) ($invoice->booking->pax_name ?? ''),
                    'total' => round((float) $leg->net_amount, 3),
                ];
            }

            // A bill with no journeys behind it — a late fee, or one raised from
            // a quote and not yet dispatched. It is still money owed, so it gets
            // its own line rather than vanishing from a document that has to add
            // up to what is being asked for.
            if ($legs->isEmpty()) {
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
                    'booking' => (string) ($invoice->booking->reference ?? ''),
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

        $dates = $invoices->pluck('issue_date')->filter()->sort()->values();

        return [
            'customer' => $invoices->first()?->customer,
            'rows' => $rows,
            'references' => $invoices->pluck('reference')->filter()->values()->all(),
            'periodFrom' => $dates->first(),
            'periodTo' => $dates->last(),
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
