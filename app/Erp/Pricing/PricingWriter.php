<?php

declare(strict_types=1);

namespace App\Erp\Pricing;

use App\Erp\Activity\ActivityLogger;
use App\Models\Pricing\PricingExtraHour;
use App\Models\Pricing\PricingOffer;
use App\Models\Pricing\PricingOption;
use App\Models\Pricing\PricingRate;
use App\Models\Pricing\PricingService;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * The one door every pricing write goes through.
 *
 * Two things have to be true of a fare change and can't be left to call sites:
 * the version bumps exactly once per save (the website's ETag depends on it),
 * and who changed what from what is on the record. Nobody could say who set
 * the contradictory prices that started this project; that must not be true of
 * the next set.
 */
final class PricingWriter
{
    public function __construct(private readonly ActivityLogger $activity) {}

    /**
     * Run a set of pricing writes as one save: one transaction, one version
     * bump, one activity-log entry. Returns the new version.
     *
     * @param  Closure(): list<string>  $work  performs the writes, returns human lines describing what changed
     */
    public function transaction(string $subject, Closure $work): int
    {
        /** @var array{int, list<string>} $result */
        $result = DB::transaction(function () use ($work): array {
            $changes = $work();

            // Nothing actually moved — don't bump, or the website re-fetches
            // an identical payload every time someone opens and saves a form.
            if ($changes === []) {
                return [PricingVersion::current(), []];
            }

            return [PricingVersion::bump(), $changes];
        });

        [$version, $changes] = $result;

        if ($changes !== []) {
            $this->activity->log(
                'pricing_updated',
                $subject,
                'v' . $version . ' — ' . implode('; ', $changes),
            );
        }

        return $version;
    }

    /**
     * Set one fare, returning a description when it actually changed.
     *
     * A null amount removes the rate: the website then hides that car for that
     * option, which is the honest answer when no price has been set. It is
     * never published as zero.
     */
    public function setRate(PricingOption $option, string $carId, ?float $amount): ?string
    {
        $existing = PricingRate::query()
            ->where('option_id', $option->id)
            ->where('car_id', $carId)
            ->first();

        $old = $existing === null ? null : round($existing->amount, 3);
        $new = $amount === null ? null : round($amount, 3);

        if ($old === $new) {
            return null;
        }

        if ($new === null) {
            $existing?->delete();
        } else {
            PricingRate::query()->updateOrCreate(
                ['option_id' => $option->id, 'car_id' => $carId],
                ['amount' => $new],
            );
        }

        return sprintf(
            '%s/%s %s → %s',
            $option->code,
            $carId,
            $old === null ? '—' : $this->money($old),
            $new === null ? '—' : $this->money($new),
        );
    }

    public function setExtraHour(PricingService $service, string $carId, ?float $amount): ?string
    {
        $existing = PricingExtraHour::query()
            ->where('service_id', $service->id)
            ->where('car_id', $carId)
            ->first();

        $old = $existing === null ? null : round($existing->amount, 3);
        $new = $amount === null ? null : round($amount, 3);

        if ($old === $new) {
            return null;
        }

        if ($new === null) {
            $existing?->delete();
        } else {
            PricingExtraHour::query()->updateOrCreate(
                ['service_id' => $service->id, 'car_id' => $carId],
                ['amount' => $new],
            );
        }

        return sprintf(
            'extra hour/%s %s → %s',
            $carId,
            $old === null ? '—' : $this->money($old),
            $new === null ? '—' : $this->money($new),
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateOffer(PricingService $service, array $attributes): ?string
    {
        $offer = PricingOffer::query()->firstOrNew(['service_id' => $service->id]);

        $before = [
            'active' => (bool) $offer->active,
            'percent' => round((float) $offer->percent, 2),
            'starts_at' => $offer->starts_at?->toDateString(),
            'ends_at' => $offer->ends_at?->toDateString(),
        ];

        $offer->fill($attributes);
        $offer->service_id = $service->id;

        $after = [
            'active' => (bool) $offer->active,
            'percent' => round((float) $offer->percent, 2),
            'starts_at' => $offer->starts_at?->toDateString(),
            'ends_at' => $offer->ends_at?->toDateString(),
        ];

        if (! $offer->isDirty() && $offer->exists) {
            return null;
        }

        $offer->save();

        if ($before === $after) {
            // Only the labels moved — worth recording, not worth spelling out.
            return 'offer labels updated';
        }

        return sprintf(
            'offer %s %s%% → %s %s%%',
            $before['active'] ? 'on' : 'off',
            $before['percent'],
            $after['active'] ? 'on' : 'off',
            $after['percent'],
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateService(PricingService $service, array $attributes): ?string
    {
        $service->fill($attributes);

        if (! $service->isDirty()) {
            return null;
        }

        $changed = array_keys($service->getDirty());
        $service->save();

        return 'service ' . implode(', ', $changed) . ' updated';
    }

    public function setOptionActive(PricingOption $option, bool $active): ?string
    {
        if ($option->active === $active) {
            return null;
        }

        $option->active = $active;
        $option->save();

        return sprintf('%s %s', $option->code, $active ? 'shown' : 'hidden');
    }

    private function money(float $amount): string
    {
        return rtrim(rtrim(number_format($amount, 3, '.', ''), '0'), '.');
    }
}
