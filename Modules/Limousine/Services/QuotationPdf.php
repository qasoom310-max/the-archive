<?php

declare(strict_types=1);

namespace Modules\Limousine\Services;

use App\Erp\Settings\Setting;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Modules\Limousine\Models\LimoQuotation;

/**
 * The quotation as the customer receives it: what was asked for, trip by trip,
 * and what it comes to.
 *
 * Priced from the LEGS rather than the header total, because that is where the
 * price actually lives — a quote for three journeys has to show the three, or
 * the customer is being asked to accept a number with nothing behind it.
 */
final class QuotationPdf
{
    /**
     * @return array<string, mixed>
     */
    public function viewData(LimoQuotation $quote): array
    {
        $quote->loadMissing(['customer', 'legs']);

        return [
            'quote' => $quote,
            'reference' => (string) ($quote->reference ?? ''),
            'customerName' => (string) ($quote->customer->name ?? ''),
            'customerPhone' => (string) ($quote->customer->phone ?? ''),
            'contactPerson' => (string) ($quote->contact_person ?? ''),
            'requestedBy' => (string) ($quote->requested_by ?? ''),
            'preparedBy' => (string) ($quote->prepared_by ?? ''),
            'quoteDate' => $quote->quote_date?->isoFormat('DD-MMM-YYYY') ?? '',
            'validUntil' => $quote->valid_until?->isoFormat('DD-MMM-YYYY') ?? '',
            'legs' => $quote->legs,
            'total' => round((float) $quote->fare, 3),
            'notes' => (string) ($quote->notes ?? ''),
            'companyName' => (string) Setting::get('company.name', 'OpenERP'),
            'companyPhone' => (string) Setting::get('company.phone', ''),
            'companyEmail' => (string) Setting::get('company.email', ''),
            'logoPath' => $this->logoPath(),
            'logoScale' => $this->logoScale(),
        ];
    }

    public function render(LimoQuotation $quote): string
    {
        return Pdf::loadView('limousine::quotation-pdf', $this->viewData($quote))
            ->setPaper('a4')
            ->output();
    }

    public function filename(LimoQuotation $quote): string
    {
        return 'quotation-' . str_replace(['/', '\\', ' '], '-', (string) $quote->reference) . '.pdf';
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
