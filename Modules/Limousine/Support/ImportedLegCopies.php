<?php

declare(strict_types=1);

namespace Modules\Limousine\Support;

use Illuminate\Support\Collection;
use Modules\Limousine\Models\LimoLeg;

/**
 * Tells a leg the September import COPIED from a journey that merely looks
 * like another one — so a bill prints every journey once, and only once.
 *
 * The import that carried the old bookings over duplicated a handful of legs
 * byte for byte, and printing the same journey twice reads as a double charge.
 * Both invoice documents used to drop any leg identical to another in service,
 * car, route, start time and price. But looking identical is not being a copy:
 * a delegation that needs three cars at 08:00, on one route, at one rate, is
 * three journeys, each billed. A Ministry of Defence booking of 18 trips
 * printed 14, under a total that still counted all 18.
 *
 * The booking's own fare tells them apart. A fare is the sum of its legs, so
 * legs that ALREADY add up to the fare are exactly the journeys it charges
 * for, however alike two may look, and nothing is removed. Only when they add
 * up to MORE (an imported booking's fare came from the old system, which never
 * counted the copies) are look-alikes removed, one at a time, and only until
 * the legs reach the fare. Past that point a look-alike is a real journey.
 *
 * Measured against the fare rather than an invoice on purpose: an invoice
 * stops following its booking once money lands, and a trip added after that
 * is unbilled, not copied.
 */
final class ImportedLegCopies
{
    /** Rounding slack on 3-dp money, so 0.1 + 0.2 never looks like a copy. */
    private const EPSILON = 0.0005;

    /**
     * The legs, minus only the copies the fare says are not journeys.
     * Re-indexed, so a dropped copy never leaves a gap in the line numbers.
     *
     * @param  Collection<int, LimoLeg>  $legs  billable legs of ONE booking or quote
     * @param  float  $fare  what that booking or quote charges for them
     * @return Collection<int, LimoLeg>
     */
    public static function without(Collection $legs, float $fare): Collection
    {
        $sum = self::sum($legs);

        if ($sum <= $fare + self::EPSILON) {
            return $legs->values();
        }

        $seen = [];
        $drop = [];

        foreach ($legs as $key => $leg) {
            $signature = self::signature($leg);

            if (! isset($seen[$signature])) {
                $seen[$signature] = true;

                continue;
            }

            $amount = round((float) $leg->net_amount, 3);

            // Taking this one out would leave the legs short of the fare, so
            // the fare is paying for it: a journey, not a copy.
            if ($sum - $amount < $fare - self::EPSILON) {
                continue;
            }

            $drop[$key] = true;
            $sum = round($sum - $amount, 3);

            if ($sum <= $fare + self::EPSILON) {
                break;
            }
        }

        return $legs->reject(static fn (LimoLeg $leg, int $key): bool => isset($drop[$key]))->values();
    }

    /**
     * @param  Collection<int, LimoLeg>  $legs
     */
    private static function sum(Collection $legs): float
    {
        return round((float) $legs->sum(static fn (LimoLeg $leg): float => (float) $leg->net_amount), 3);
    }

    /** What an import copy shares with its original, byte for byte. */
    private static function signature(LimoLeg $leg): string
    {
        return implode('|', [
            $leg->service_type,
            (string) $leg->vehicle_details,
            (string) $leg->from_location,
            (string) $leg->to_location,
            (string) $leg->start_at,
            (string) $leg->net_amount,
        ]);
    }
}
