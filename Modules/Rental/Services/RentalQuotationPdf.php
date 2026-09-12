<?php

declare(strict_types=1);

namespace Modules\Rental\Services;

use App\Erp\Settings\Setting;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Modules\Rental\Models\RentalOrder;
use Modules\Rental\Models\RentalQuotation;

/**
 * The quotation as the customer receives it — same visual family as the
 * Rental invoice (itself matching the Limousine reference template), with
 * the item-table columns the owner pointed at on the old system's printed
 * quotation: No. / Service / Vehicle / Period (From/To) / Days / Rate /
 * Amount, and a Subtotal/Discount/VAT/Total box.
 *
 * A rental quotation prices exactly ONE vehicle for ONE period — unlike the
 * Limousine quotation's per-leg lines — so there is at most a single item
 * row, built straight from the quotation's own columns (it carries no order
 * yet; that only exists once the quote is converted).
 *
 * VAT is NOT a stored column on {@see RentalQuotation} (unlike RentalOrder,
 * which persists vat_rate/vat_amount once a real order exists) — it is
 * computed here for display only, at the same {@see RentalOrder::DEFAULT_VAT_RATE}
 * every order defaults to, so a quotation previews the same tax an accepted
 * order would actually charge without needing a schema change.
 */
final class RentalQuotationPdf
{
    /**
     * @return array<string, mixed>
     */
    public function viewData(RentalQuotation $quote): array
    {
        $quote->loadMissing(['customer', 'vehicle']);

        $subtotal = round((float) $quote->subtotal, 3);
        $discount = round((float) $quote->discount, 3);
        $vatRate = RentalOrder::DEFAULT_VAT_RATE;
        $vatAmount = round(max(0.0, $subtotal - $discount) * ($vatRate / 100), 3);
        $total = round($subtotal - $discount + $vatAmount, 3);

        return [
            'quote' => $quote,
            'reference' => (string) ($quote->reference ?? ''),
            'quoteDate' => $quote->created_at?->format('j-M-Y') ?? '',
            'validUntil' => $quote->valid_until?->format('j-M-Y') ?? '',
            'customerName' => (string) ($quote->customer->name ?? ''),
            'customerPhone' => (string) ($quote->customer->phone ?? ''),
            'lines' => $this->lines($quote),
            'subtotal' => $subtotal,
            'discount' => $discount,
            'vatRate' => $vatRate,
            'vatAmount' => $vatAmount,
            'total' => $total,
            'deposit' => round((float) $quote->deposit, 3),
            'notes' => (string) ($quote->notes ?? ''),
            'companyName' => (string) Setting::get('company.name', 'OpenERP'),
            'companyPhone' => (string) Setting::get('company.phone', ''),
            'companyEmail' => (string) Setting::get('company.email', ''),
            'logoPath' => $this->logoPath(),
            'logoScale' => $this->logoScale(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lines(RentalQuotation $quote): array
    {
        if ($quote->vehicle === null) {
            return [];
        }

        $vehicle = $quote->vehicle;
        $carType = trim(trim((string) ($vehicle->make ?? '')) . ' ' . trim((string) ($vehicle->model ?? '')));

        return [[
            'service' => __('Rental'),
            'vehicle' => trim(trim((string) ($vehicle->plate_no ?? '')) . ' ' . $carType),
            'from' => $quote->start_date?->format('j-n-Y') ?? '',
            'to' => $quote->end_date?->format('j-n-Y') ?? '',
            'units' => $quote->billableUnits(),
            'unitLabel' => $this->periodUnitLabel($quote->rate_type),
            'rate' => round((float) $quote->rate, 3),
            'amount' => round((float) $quote->subtotal, 3),
        ]];
    }

    /** "days" / "weeks" / "months", matching the quotation's billing period. */
    private function periodUnitLabel(string $rateType): string
    {
        return match ($rateType) {
            'weekly' => __('weeks'),
            'monthly' => __('months'),
            default => __('days'),
        };
    }

    public function render(RentalQuotation $quote): string
    {
        return Pdf::loadView('rental::quotation-pdf', $this->viewData($quote))
            ->setPaper('a4')
            ->output();
    }

    /**
     * Several quotations as one PDF — one full page per quotation, in the
     * same design {@see render()} produces. Used when more than one row is
     * ticked on the quotations list and "PDF" is pressed.
     *
     * @param  Collection<int, RentalQuotation>  $quotations
     */
    public function renderMany(Collection $quotations): string
    {
        return Pdf::loadView('rental::quotations-batch-pdf', [
            'quotations' => $quotations->map(fn (RentalQuotation $quote): array => $this->viewData($quote))->all(),
        ])
            ->setPaper('a4')
            ->output();
    }

    public function filename(RentalQuotation $quote): string
    {
        return 'quotation-' . str_replace(['/', '\\', ' '], '-', (string) $quote->reference) . '.pdf';
    }

    /** Filename for a batch download of several ticked quotations. */
    public function filenameForMany(int $count): string
    {
        return 'quotations-' . $count . '-' . now()->format('Ymd-His') . '.pdf';
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
