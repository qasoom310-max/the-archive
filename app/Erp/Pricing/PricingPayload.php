<?php

declare(strict_types=1);

namespace App\Erp\Pricing;

use App\Models\Pricing\PricingCar;
use App\Models\Pricing\PricingOption;
use App\Models\Pricing\PricingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Builds the published fares exactly as the website consumes them.
 *
 * Two rules carry all the risk and are enforced here rather than trusted to
 * the caller:
 *
 *  - A missing rate is NOT a zero. A car with no fare for an option is omitted
 *    from that option; a zero on a public page is worse than a missing car.
 *  - An offer's `active` is resolved here, against the server's clock. The
 *    website must never decide whether a discount has expired.
 */
final class PricingPayload
{
    /**
     * @return array<string, mixed>
     */
    public function build(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        $cars = PricingCar::query()
            ->where('active', true)
            ->orderBy('sort')
            ->orderBy('id')
            ->get();

        // Eager-load everything the loop below touches: without this a dozen
        // options each fetch their own rates and the 500ms budget is gone.
        $services = PricingService::query()
            ->where('active', true)
            ->with([
                'options' => fn ($q) => $q->where('active', true),
                'options.rates',
                'extraHours',
                'offer',
            ])
            ->orderBy('sort')
            ->orderBy('id')
            ->get();

        $activeCarIds = $cars->pluck('id')->all();

        return [
            'version' => PricingVersion::current(),
            'updated_at' => (PricingVersion::updatedAt() ?? $now)->toIso8601String(),
            'currency' => 'BHD',
            'cars' => $cars->map(static fn (PricingCar $car): array => [
                'id' => $car->id,
                'en' => $car->name_en,
                'ar' => $car->name_ar,
                'model' => $car->model,
                'pax' => $car->pax,
                'bags' => $car->bags,
            ])->all(),
            'services' => $services->map(
                fn (PricingService $service): array => $this->service($service, $activeCarIds, $now),
            )->all(),
        ];
    }

    /**
     * @param  list<string>  $activeCarIds
     * @return array<string, mixed>
     */
    private function service(PricingService $service, array $activeCarIds, CarbonImmutable $now): array
    {
        $extraHour = [];
        foreach ($service->extraHours as $row) {
            if (in_array($row->car_id, $activeCarIds, true)) {
                $extraHour[$row->car_id] = $this->amount($row->amount);
            }
        }

        $offer = $service->offer;
        $live = $offer !== null && $offer->isLive($now);

        return [
            'id' => $service->id,
            'en' => $service->name_en,
            'ar' => $service->name_ar,
            'ask_en' => $service->ask_en,
            'ask_ar' => $service->ask_ar,
            'note_en' => $service->note_en,
            'note_ar' => $service->note_ar,
            'return_factor' => $service->return_factor === null ? null : $this->amount($service->return_factor),
            'options' => $service->options->map(
                fn (PricingOption $option): array => $this->option($option, $activeCarIds, $service->id),
            )->all(),
            'extra_hour' => (object) $extraHour,
            'offer' => [
                'active' => $live,
                // A discount that isn't live is published as zero percent as
                // well as inactive, so a site that reads only one of the two
                // fields still can't apply an expired offer.
                'percent' => $live && $offer !== null ? $this->amount($offer->percent) : 0,
                'label_en' => $live && $offer !== null ? $offer->label_en : null,
                'label_ar' => $live && $offer !== null ? $offer->label_ar : null,
                'starts' => $offer?->starts_at?->toDateString(),
                'ends' => $offer?->ends_at?->toDateString(),
            ],
        ];
    }

    /**
     * @param  list<string>  $activeCarIds
     * @return array<string, mixed>
     */
    private function option(PricingOption $option, array $activeCarIds, string $serviceId): array
    {
        $prices = [];
        foreach ($option->rates as $rate) {
            if (in_array($rate->car_id, $activeCarIds, true)) {
                $prices[$rate->car_id] = $this->amount($rate->amount);
            }
        }

        // A car with no fare here is a data hole, not a free trip. Say so in
        // the log and leave the car out — the admin screen refuses to save
        // this state, so reaching it means something bypassed the screen.
        $missing = array_values(array_diff($activeCarIds, array_keys($prices)));
        if ($missing !== []) {
            Log::warning('Pricing: option has no fare for an active car', [
                'service' => $serviceId,
                'option' => $option->code,
                'cars' => $missing,
            ]);
        }

        // Keep the published order matching the cars array, not insertion order.
        $ordered = [];
        foreach ($activeCarIds as $carId) {
            if (array_key_exists($carId, $prices)) {
                $ordered[$carId] = $prices[$carId];
            }
        }

        return [
            'code' => $option->code,
            'en' => $option->label_en,
            'ar' => $option->label_ar,
            'short_en' => $option->short_en,
            'short_ar' => $option->short_ar,
            'hours' => $option->hours,
            'prices' => (object) $ordered,
        ];
    }

    /**
     * A JSON number with trailing zeros trimmed — 15, not 15.000, and 1.8, not
     * 1.80. Integers stay integers so the website can print them as typed.
     */
    private function amount(float $value): float|int
    {
        $rounded = round($value, 3);

        return $rounded == (int) $rounded ? (int) $rounded : $rounded;
    }
}
