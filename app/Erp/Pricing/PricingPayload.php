<?php

declare(strict_types=1);

namespace App\Erp\Pricing;

use App\Models\Pricing\PricingCar;
use App\Models\Pricing\PricingOffer;
use App\Models\Pricing\PricingOption;
use App\Models\Pricing\PricingService;
use App\Models\Pricing\PricingSetting;
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
 *  - A service with no positive fare at all is never published. The widget
 *    already refuses to render one; mirroring the guard here means a
 *    half-configured service cannot reach a customer in the first place.
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
                'vehicles',
                'extraHours',
                'offer',
                'offer.cars',
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
            'settings' => $this->settings(),
            'services' => $services
                ->map(fn (PricingService $service): array => $this->service($service, $activeCarIds, $now))
                // A service nobody has priced yet is not a service. Publishing
                // one gives the customer a picker that leads to nothing.
                ->filter(fn (array $service): bool => $this->hasAnyPrice($service))
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  list<string>  $activeCarIds
     * @return array<string, mixed>
     */
    private function service(PricingService $service, array $activeCarIds, CarbonImmutable $now): array
    {
        // Only the vehicles this service actually offers, and only those still
        // active. A service that has never been given a vehicle list falls back
        // to every active one rather than publishing nothing.
        $serviceCarIds = $service->vehicles
            ->pluck('id')
            ->filter(static fn (string $id): bool => in_array($id, $activeCarIds, true))
            ->values()
            ->all();

        if ($serviceCarIds === []) {
            $serviceCarIds = $activeCarIds;
        }

        $extraHour = [];
        foreach ($service->extraHours as $row) {
            if (in_array($row->car_id, $serviceCarIds, true)) {
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
            // `estimated` is deliberately NOT here: it is an internal warning
            // for the admin screen, not something a customer should read.
            'cars' => $serviceCarIds,
            'options' => $service->options->map(
                fn (PricingOption $option): array => $this->option($option, $serviceCarIds, $service->id),
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
                // Which cars the discount applies to — not every car in a
                // service is necessarily on offer. Zeroed when not live for
                // the same reason percent/label are: a site that checks only
                // this field can't apply an expired or not-yet-started offer.
                'cars' => $live ? $this->offerCarIds($offer, $serviceCarIds) : [],
            ],
        ];
    }

    /**
     * @param  list<string>  $serviceCarIds
     * @return list<string>
     */
    private function offerCarIds(?PricingOffer $offer, array $serviceCarIds): array
    {
        if ($offer === null) {
            return [];
        }

        $selected = $offer->cars
            ->pluck('id')
            ->filter(static fn (string $id): bool => in_array($id, $serviceCarIds, true))
            ->values()
            ->all();

        // Never configured (or everything was selected and later removed) —
        // fall back to every car the service offers, the same rule a service
        // with no vehicle list already uses. An offer that's ON must apply to
        // SOMETHING; the admin screen refuses to save ON with zero cars
        // ticked, so reaching this with `active=true` means legacy data.
        return $selected === [] ? $serviceCarIds : $selected;
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
     * @return array<string, mixed>
     */
    private function settings(): array
    {
        $settings = PricingSetting::current();

        return [
            'whatsapp' => $settings->whatsapp,
            'lead_hours' => $settings->lead_hours,
        ];
    }

    /**
     * Does this service have at least one real fare anywhere?
     *
     * @param  array<string, mixed>  $service
     */
    private function hasAnyPrice(array $service): bool
    {
        /** @var list<array<string, mixed>> $options */
        $options = $service['options'];

        foreach ($options as $option) {
            foreach ((array) $option['prices'] as $amount) {
                if ((float) $amount > 0) {
                    return true;
                }
            }
        }

        return false;
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
