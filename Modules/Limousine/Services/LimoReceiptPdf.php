<?php

declare(strict_types=1);

namespace Modules\Limousine\Services;

use App\Erp\Settings\Setting;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Modules\Limousine\Models\LimoReceipt;

/**
 * The customer's copy of a payment: proof they handed money over.
 *
 * A row in our list is our record, not theirs. This is the thing they keep —
 * what was paid, on what date, against which job, and what is still owed.
 *
 * The balance is read from the receipt, NOT recomputed. A receipt is a record
 * of a moment: reprint one after a later payment and a recomputed figure would
 * make the paper quietly disagree with itself about what was owed that day.
 */
final class LimoReceiptPdf
{
    /**
     * @return array<string, mixed>
     */
    public function viewData(LimoReceipt $receipt): array
    {
        $receipt->loadMissing(['customer', 'invoice', 'booking']);

        $amount = round((float) $receipt->amount, 3);
        // Stored on the receipt at the moment it was written. Null on older
        // rows that predate the column — those simply omit the line rather than
        // inventing a figure from today's numbers.
        $balance = $receipt->balance_after !== null ? round((float) $receipt->balance_after, 3) : null;

        return [
            'receipt' => $receipt,
            'reference' => (string) ($receipt->reference ?? ''),
            // "5-Sep-2026", matching the invoice's header-date format.
            'date' => $receipt->date?->format('j-M-Y') ?? '',
            'customerName' => (string) ($receipt->customer->name ?? ''),
            'customerPhone' => (string) ($receipt->customer->phone ?? ''),
            // What the money was FOR. A receipt naming neither a booking nor an
            // invoice tells the customer nothing they can match to a journey.
            'bookingReference' => (string) ($receipt->booking->reference ?? ''),
            'invoiceReference' => (string) ($receipt->invoice->reference ?? ''),
            'amount' => $amount,
            'balance' => $balance,
            'method' => __(ucfirst((string) $receipt->method)),
            'notes' => (string) ($receipt->notes ?? ''),
            // The account that raised it, by the name it signs in under.
            // Blank on rows written before the column existed — those print
            // no line rather than naming somebody who did not raise them.
            'preparedBy' => (string) ($receipt->prepared_by ?? ''),
            'companyName' => (string) Setting::get('company.name', 'OpenERP'),
            'companyPhone' => (string) Setting::get('company.phone', ''),
            'companyEmail' => (string) Setting::get('company.email', ''),
            'logoPath' => $this->logoPath(),
            'logoScale' => $this->logoScale(),
        ];
    }

    /** Rendered PDF bytes. */
    public function render(LimoReceipt $receipt): string
    {
        return Pdf::loadView('limousine::receipt-pdf', $this->viewData($receipt))
            ->setPaper('a4')
            ->output();
    }

    public function filename(LimoReceipt $receipt): string
    {
        return 'receipt-' . str_replace(['/', '\\', ' '], '-', (string) $receipt->reference) . '.pdf';
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
