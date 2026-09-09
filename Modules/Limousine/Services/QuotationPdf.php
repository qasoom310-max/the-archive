<?php

declare(strict_types=1);

namespace Modules\Limousine\Services;

use App\Erp\Settings\Setting;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Models\LimoQuotation;

/**
 * The quotation as the customer receives it: what was asked for, trip by trip,
 * and what it comes to.
 *
 * Priced from the LEGS rather than the header total, because that is where the
 * price actually lives — a quote for three journeys has to show the three, or
 * the customer is being asked to accept a number with nothing behind it.
 *
 * Same visual family as the invoice/receipt (`invoice-pdf.blade.php` /
 * `receipt-pdf.blade.php`), with the item-table columns the owner pointed at
 * on the old system's printed quotation: Service / Vehicle Type / From / To /
 * Days-Trips / Unit / Rate per Unit / Amount, and a Subtotal/Discount/VAT/Total
 * box built from each leg's own figures rather than just the header fare.
 */
final class QuotationPdf
{
    /**
     * @return array<string, mixed>
     */
    public function viewData(LimoQuotation $quote): array
    {
        $quote->loadMissing(['customer', 'legs']);
        $legs = $quote->legs;

        return [
            'quote' => $quote,
            'reference' => (string) ($quote->reference ?? ''),
            'customerName' => (string) ($quote->customer->name ?? ''),
            'customerPhone' => (string) ($quote->customer->phone ?? ''),
            'contactPerson' => (string) ($quote->contact_person ?? ''),
            'contactNumber' => (string) ($quote->contact_number ?? ''),
            'requestedBy' => (string) ($quote->requested_by ?? ''),
            'preparedBy' => (string) ($quote->prepared_by ?? ''),
            'quoteDate' => $quote->quote_date?->format('j-M-Y') ?? '',
            'validUntil' => $quote->valid_until?->format('j-M-Y') ?? '',
            'lines' => $this->lines($legs),
            'subtotal' => round((float) $legs->sum(
                static fn (LimoLeg $leg): float => LimoLeg::grossFor($leg->rate_basis, (float) $leg->rate, $leg->hours, $leg->days),
            ), 3),
            'discount' => round((float) $legs->sum('discount'), 3),
            'vat' => round((float) $legs->sum('vat'), 3),
            // The header fare, not re-summed from the lines above — it is the
            // figure already agreed/stored, and per-leg rounding could nudge a
            // re-sum by a cent either way.
            'total' => round((float) $quote->fare, 3),
            'notes' => (string) ($quote->notes ?? ''),
            'companyName' => (string) Setting::get('company.name', 'OpenERP'),
            'companyPhone' => (string) Setting::get('company.phone', ''),
            'companyEmail' => (string) Setting::get('company.email', ''),
            'logoPath' => $this->logoPath(),
            'logoScale' => $this->logoScale(),
        ];
    }

    /**
     * @param Collection<int, LimoLeg> $legs
     * @return list<array<string, mixed>>
     */
    private function lines(Collection $legs): array
    {
        return $legs->map(fn (LimoLeg $leg): array => $this->legRow($leg))->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function legRow(LimoLeg $leg): array
    {
        // Rate × Unit × Days/Trips reproduces LimoLeg::grossFor() for every
        // basis: per-trip is 1×1×rate (flat), per-day is 1×days×rate, and
        // per-hour is hours×days×rate — so the printed columns multiply out
        // to the same figure the booking is actually priced by.
        $unit = $leg->rate_basis === LimoLeg::BASIS_HOUR ? (float) ($leg->hours ?? 0) : 1.0;
        $daysTrips = $leg->rate_basis === LimoLeg::BASIS_TRIP ? 1 : $leg->days;

        return [
            'service' => __(ucfirst(str_replace('_', ' ', $leg->service_type))),
            'vehicle' => (string) ($leg->vehicle_details ?? ''),
            'from' => (string) ($leg->from_location ?? ''),
            'to' => (string) ($leg->to_location ?? ''),
            'unit' => $unit,
            'daysTrips' => $daysTrips,
            'rate' => round((float) $leg->rate, 3),
            'amount' => LimoLeg::grossFor($leg->rate_basis, (float) $leg->rate, $leg->hours, $leg->days),
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
