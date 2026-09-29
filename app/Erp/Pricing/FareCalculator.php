<?php

declare(strict_types=1);

namespace App\Erp\Pricing;

use App\Models\Pricing\PricingCar;
use App\Models\Pricing\PricingCorporateRate;
use App\Models\Pricing\PricingOffer;
use App\Models\Pricing\PricingOption;
use App\Models\Pricing\PricingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

/**
 * Prices one trip from the `pricing_*` tables — the same fares the website's
 * Fare Finder publishes, so a staff quote and a customer quote cannot differ.
 *
 * The rules mirror {@see PricingPayload} and the website:
 *  - the fare is the option's rate for that car; NO rate means NO fare (never
 *    zero, never a guess);
 *  - a round trip multiplies by the service's `return_factor` (a service
 *    without one has no round-trip price);
 *  - hours beyond an hourly option's block are charged at the service's
 *    per-car extra-hour rate (no rate = no fare for the extra hours);
 *  - a live offer takes its percent off when the car is on the offer and the
 *    TRAVEL date falls inside the offer's window.
 *
 * Given a company, its agreed corporate rate wins (its own, else the standard
 * corporate rate — see {@see PricingCorporateRate}), with no offer on top; a
 * cell with no corporate rate is the website fare as above.
 */
final class FareCalculator
{
    /**
     * Everything a caller may choose from: active services with their active
     * options and the cars each one offers.
     *
     * @return list<array<string, mixed>>
     */
    public function catalogue(): array
    {
        return PricingService::query()
            ->where('active', true)
            ->with(['options' => fn ($q) => $q->where('active', true)->orderBy('sort'), 'vehicles'])
            ->orderBy('sort')
            ->get()
            ->map(function (PricingService $service): array {
                return [
                    'service' => $service->id,
                    'name_en' => $service->name_en,
                    'name_ar' => $service->name_ar,
                    'round_trip_available' => $service->return_factor !== null,
                    'cars' => $this->carsFor($service),
                    'options' => $service->options->map(static fn (PricingOption $o): array => [
                        'code' => $o->code,
                        'label_en' => $o->label_en,
                        'label_ar' => $o->label_ar,
                        'hours' => $o->hours,
                    ])->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

    public function quote(
        string $serviceId,
        string $carId,
        string $optionCode,
        bool $roundTrip = false,
        float $extraHours = 0.0,
        ?CarbonImmutable $travelDate = null,
        ?CarbonImmutable $now = null,
        ?int $companyId = null,
    ): FareResult {
        $service = PricingService::query()->where('active', true)->with(['vehicles', 'offer.cars'])->find($serviceId);
        if (! $service instanceof PricingService) {
            return FareResult::missing('unknown_service');
        }

        $car = PricingCar::query()->where('active', true)->find($carId);
        if (! $car instanceof PricingCar || ! in_array($car->id, $this->carsFor($service), true)) {
            return FareResult::missing('car_not_offered');
        }

        // An option switched off on the website can still carry a company's
        // agreed rate (a route only corporate customers are offered), so for a
        // company the option is looked up whether or not it is shown.
        $option = PricingOption::query()
            ->where('service_id', $service->id)
            ->where('code', $optionCode)
            ->when($companyId === null, fn ($q) => $q->where('active', true))
            ->with('rates')
            ->first();
        if (! $option instanceof PricingOption) {
            return FareResult::missing('unknown_option');
        }

        $corporate = $companyId !== null && Schema::hasTable('pricing_corporate_rates')
            ? PricingCorporateRate::lookup($companyId, $option->id, $car->id)
            : null;

        if ($corporate !== null) {
            $base = $corporate['amount'];
            $source = $corporate['source'];
        } else {
            if (! $option->active) {
                return FareResult::missing('unknown_option');
            }

            $rate = $option->rates->firstWhere('car_id', $car->id);
            if ($rate === null || (float) $rate->amount <= 0) {
                return FareResult::missing('no_fare');
            }

            $base = (float) $rate->amount;
            $source = FareResult::SOURCE_WEBSITE;
        }

        $amount = $base;

        if ($roundTrip) {
            if ($service->return_factor === null) {
                return FareResult::missing('no_round_trip');
            }
            $amount *= (float) $service->return_factor;
        }

        $extraAmount = 0.0;
        if ($extraHours > 0) {
            $perHour = $service->extraHours()->where('car_id', $car->id)->value('amount');
            if ($perHour === null || (float) $perHour <= 0) {
                return FareResult::missing('no_extra_hour_rate');
            }
            $extraAmount = (float) $perHour * $extraHours;
            $amount += $extraAmount;
        }

        // An agreed corporate price is the deal itself: a website offer does
        // not come off it as well.
        $percent = $source === FareResult::SOURCE_WEBSITE
            ? $this->offerPercent($service, $car->id, $travelDate, $now ?? CarbonImmutable::now())
            : 0.0;
        $discount = $percent > 0 ? round($amount * $percent / 100, 3) : 0.0;

        return new FareResult(
            found: true,
            reason: null,
            serviceId: $service->id,
            serviceEn: $service->name_en,
            serviceAr: $service->name_ar,
            carId: $car->id,
            carEn: $car->name_en,
            carAr: $car->name_ar,
            optionCode: $option->code,
            optionEn: $option->label_en,
            optionAr: $option->label_ar,
            hours: $option->hours,
            roundTrip: $roundTrip,
            extraHours: $extraHours,
            base: round($base, 3),
            extraHoursAmount: round($extraAmount, 3),
            discountPercent: $percent,
            discount: $discount,
            total: round($amount - $discount, 3),
            source: $source,
        );
    }

    /**
     * @return list<string>
     */
    private function carsFor(PricingService $service): array
    {
        $active = PricingCar::query()->where('active', true)->orderBy('sort')->pluck('id')->all();
        $offered = array_values(array_filter(
            $service->vehicles->pluck('id')->all(),
            static fn (string $id): bool => in_array($id, $active, true),
        ));

        // Same fallback the published payload uses: a service never given a
        // vehicle list offers every active car.
        return $offered === [] ? $active : $offered;
    }

    private function offerPercent(PricingService $service, string $carId, ?CarbonImmutable $travelDate, CarbonImmutable $now): float
    {
        $offer = $service->offer;
        if (! $offer instanceof PricingOffer || ! $offer->isLive($now) || (float) $offer->percent <= 0) {
            return 0.0;
        }

        $day = ($travelDate ?? $now)->startOfDay();
        if ($offer->starts_at !== null && $day->lessThan(CarbonImmutable::instance($offer->starts_at)->startOfDay())) {
            return 0.0;
        }
        if ($offer->ends_at !== null && $day->greaterThan(CarbonImmutable::instance($offer->ends_at)->startOfDay())) {
            return 0.0;
        }

        $cars = $offer->cars->pluck('id')->all();
        if ($cars !== [] && ! in_array($carId, $cars, true)) {
            return 0.0;
        }

        return (float) $offer->percent;
    }
}
