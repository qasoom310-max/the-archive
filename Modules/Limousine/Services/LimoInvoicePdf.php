<?php

declare(strict_types=1);

namespace Modules\Limousine\Services;

use App\Erp\Settings\Setting;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Modules\Limousine\Models\LimoInvoice;
use Modules\Limousine\Models\LimoLeg;

/**
 * The customer's bill: what is owed, for which journeys, and what is left.
 *
 * The office needs to hand this over, so it is a document rather than a screen.
 * It lists the LEGS behind the total — a bill that states one figure and no
 * journeys is one the customer has to ring up to understand.
 *
 * Where the legs come from depends on where the invoice came from: a trip
 * carries its own, and an invoice raised from a quote borrows the quote's,
 * because between billing and dispatch there is no trip yet. Neither is the
 * source of the TOTAL — that is the invoice's own, which stops following the
 * job once money lands.
 */
final class LimoInvoicePdf
{
    /**
     * @return array<string, mixed>
     */
    public function viewData(LimoInvoice $invoice): array
    {
        $invoice->loadMissing(['customer', 'booking.legs', 'quotation.legs']);

        // ?? already suppresses the null property access, so no nullsafe on
        // the left: a bill with no trip falls through to the quote's legs.
        $legs = $invoice->booking->legs ?? $invoice->quotation?->legs;
        $paid = round((float) $invoice->amount_paid, 3);
        $total = round((float) $invoice->total, 3);

        return [
            'invoice' => $invoice,
            'reference' => (string) ($invoice->reference ?? ''),
            'issueDate' => $invoice->issue_date?->isoFormat('DD-MMM-YYYY') ?? '',
            'dueDate' => $invoice->due_date?->isoFormat('DD-MMM-YYYY') ?? '',
            'customerName' => (string) ($invoice->customer->name ?? ''),
            'customerPhone' => (string) ($invoice->customer->phone ?? ''),
            // What the bill is against, so the customer can match it to a job.
            'bookingReference' => (string) ($invoice->booking->reference ?? ''),
            'quotationReference' => (string) ($invoice->quotation->reference ?? ''),
            'lines' => $this->lines($legs),
            'subtotal' => round((float) $invoice->subtotal, 3),
            'discount' => round((float) $invoice->discount, 3),
            'total' => $total,
            'paid' => $paid,
            'balance' => round($total - $paid, 3),
            'status' => (string) $invoice->status,
            'notes' => (string) ($invoice->notes ?? ''),
            'companyName' => (string) Setting::get('company.name', 'OpenERP'),
            'companyPhone' => (string) Setting::get('company.phone', ''),
            'companyEmail' => (string) Setting::get('company.email', ''),
            'logoPath' => $this->logoPath(),
            'logoScale' => $this->logoScale(),
        ];
    }

    /**
     * One printable row per journey.
     *
     * @param  \Illuminate\Support\Collection<int, LimoLeg>|null  $legs
     * @return list<array{description: string, when: string, amount: float}>
     */
    private function lines(?\Illuminate\Support\Collection $legs): array
    {
        if ($legs === null) {
            return [];
        }

        $rows = [];
        foreach ($legs as $leg) {
            $route = array_filter([$leg->from_location, $leg->to_location]);

            $rows[] = [
                'description' => trim(__(ucfirst(str_replace('_', ' ', $leg->service_type)))
                    . ($route !== [] ? ' — ' . implode(' → ', $route) : '')),
                'when' => $leg->start_at?->isoFormat('DD-MMM-YYYY HH:mm') ?? '',
                'amount' => round((float) $leg->net_amount, 3),
            ];
        }

        return $rows;
    }

    /** Rendered PDF bytes. */
    public function render(LimoInvoice $invoice): string
    {
        return Pdf::loadView('limousine::invoice-pdf', $this->viewData($invoice))
            ->setPaper('a4')
            ->output();
    }

    public function filename(LimoInvoice $invoice): string
    {
        return 'invoice-' . str_replace(['/', '\\', ' '], '-', (string) $invoice->reference) . '.pdf';
    }

    /** Logo size as a percent of default, clamped to a sane 50–400 %. */
    private function logoScale(): int
    {
        $raw = (int) Setting::get('company.logo_scale', 100);

        return max(50, min(400, $raw > 0 ? $raw : 100));
    }

    /** Filesystem path to the company logo, or null when absent/missing. */
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
