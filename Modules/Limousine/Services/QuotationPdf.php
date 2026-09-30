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
 *
 * Every entry point also takes `$withoutTotal`, which leaves the Subtotal /
 * Discount / VAT / Total box off the sheet. The trips and their rates stay:
 * what goes is the figure at the bottom that reads as a commitment to the
 * whole list. A customer being quoted several journeys is often choosing
 * between them, and a total tells them they are buying all of it.
 */
final class QuotationPdf
{
    /**
     * @return array<string, mixed>
     */
    public function viewData(LimoQuotation $quote, bool $withoutTotal = false): array
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
            // The quote's own header car answers for a leg that names none.
            'lines' => $this->lines($legs, (string) ($quote->car_type ?? '')),
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
            // Still computed above and simply not printed: the same sheet either
            // way, so the two versions can never disagree about the trips.
            'withoutTotal' => $withoutTotal,
        ];
    }

    /**
     * @param Collection<int, LimoLeg> $legs
     * @return list<array<string, mixed>>
     */
    private function lines(Collection $legs, string $fallbackType = ''): array
    {
        return $legs->map(fn (LimoLeg $leg): array => $this->legRow($leg, $fallbackType))->all();
    }

    /**
     * The car this leg is quoted on, as the Vehicle Type column wants it.
     *
     * The quotation form offers TWO ways to say it — a free-text "Car details"
     * box per leg (`vehicle_details`) and a pick from the fleet (`car_id`,
     * whose label is snapshotted into `vehicle`) — and the sheet used to read
     * only the first. A quote written by picking the car printed an empty
     * column, which is what the office hit.
     *
     * A picked car is stored as "Ford Expedition · 363899 · White", so only the
     * name is taken: the column is headed Vehicle Type, the quote is not a
     * dispatch, and no customer is choosing by plate.
     *
     * Deliberately NOT the rule the invoices use. There, `vehicle` may be the
     * car the QUEUE assigned at dispatch, and a customer who agreed to an SUV
     * must not be billed by whichever plate happened to run it. A quotation has
     * no dispatch behind it, so its `vehicle` can only be the car the office
     * chose on the quote itself.
     */
    private function vehicleLabel(LimoLeg $leg, string $fallbackType): string
    {
        $details = trim((string) ($leg->vehicle_details ?? ''));

        if ($details !== '') {
            return $details;
        }

        $picked = trim((string) ($leg->vehicle ?? ''));

        if ($picked !== '') {
            $name = trim((string) (explode('·', $picked)[0] ?? ''));

            return $name !== '' ? $name : $picked;
        }

        return trim($fallbackType);
    }

    /**
     * @return array<string, mixed>
     */
    private function legRow(LimoLeg $leg, string $fallbackType = ''): array
    {
        // Rate × Unit × Days/Trips reproduces LimoLeg::grossFor() for every
        // basis: per-trip is 1×1×rate (flat), per-day is 1×days×rate, and
        // per-hour is hours×days×rate — so the printed columns multiply out
        // to the same figure the booking is actually priced by.
        $unit = $leg->rate_basis === LimoLeg::BASIS_HOUR ? (float) ($leg->hours ?? 0) : 1.0;
        $daysTrips = $leg->rate_basis === LimoLeg::BASIS_TRIP ? 1 : $leg->days;

        return [
            'service' => __(ucfirst(str_replace('_', ' ', $leg->service_type))),
            'vehicle' => $this->vehicleLabel($leg, $fallbackType),
            'from' => (string) ($leg->from_location ?? ''),
            'to' => (string) ($leg->to_location ?? ''),
            'unit' => $unit,
            'daysTrips' => $daysTrips,
            'rate' => round((float) $leg->rate, 3),
            'amount' => LimoLeg::grossFor($leg->rate_basis, (float) $leg->rate, $leg->hours, $leg->days),
        ];
    }

    public function render(LimoQuotation $quote, bool $withoutTotal = false): string
    {
        return Pdf::loadView('limousine::quotation-pdf', $this->viewData($quote, $withoutTotal))
            ->setPaper('a4')
            ->output();
    }

    /**
     * Several quotations as one PDF — one full page per quotation, in the
     * same design {@see render()} produces. Used when more than one row is
     * ticked on the quotations list and "PDF" is pressed.
     *
     * @param Collection<int, LimoQuotation> $quotes
     */
    public function renderMany(Collection $quotes, bool $withoutTotal = false): string
    {
        return Pdf::loadView('limousine::quotations-batch-pdf', [
            'quotations' => $quotes->map(fn (LimoQuotation $quote): array => $this->viewData($quote, $withoutTotal))->all(),
        ])
            ->setPaper('a4')
            ->output();
    }

    public function filename(LimoQuotation $quote, bool $withoutTotal = false): string
    {
        // Named apart, because the two copies of one quotation sitting in a
        // downloads folder have to be tellable without opening them.
        return 'quotation-'
            . str_replace(['/', '\\', ' '], '-', (string) $quote->reference)
            . ($withoutTotal ? '-no-total' : '')
            . '.pdf';
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
