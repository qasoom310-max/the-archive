<?php

declare(strict_types=1);

namespace Modules\Limousine\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Models\LimoLocation;
use Modules\Rental\Models\TravelPermit;

/**
 * Does the driver being sent on this trip hold a travel authorization for
 * this car?
 *
 * A trip that leaves Bahrain needs the letter that lets a named driver take
 * the company's car across the border. Finding out at the causeway that the
 * driver has none costs the trip. So the office is told at the moment it
 * assigns the car and the driver, while there is still time to make one.
 *
 * It is a warning, never a block: the letter may exist on paper from before
 * this system kept them, and the office must still be able to dispatch.
 *
 * Only asked when it can be answered: the trip records a country other than
 * Bahrain at either end, the car is one of ours (another company's car comes
 * with that company's own paper), and both a car and a driver are chosen.
 */
final class BorderPermitCheck
{
    /** Whether this trip starts or ends outside Bahrain. */
    public function leavesBahrain(LimoLeg $leg): bool
    {
        foreach ([$leg->from_country, $leg->to_country] as $country) {
            $country = strtoupper(trim((string) $country));
            if ($country !== '' && $country !== LimoLocation::DEFAULT_COUNTRY) {
                return true;
            }
        }

        return false;
    }

    /**
     * What to tell the office, or null when there is nothing to say.
     *
     * @return array{message: string, expired: bool}|null
     */
    public function warning(LimoLeg $leg, ?int $carId, ?int $driverId): ?array
    {
        if ($carId === null || $driverId === null || ! $this->leavesBahrain($leg)) {
            return null;
        }

        if (! Schema::hasTable('rental_travel_permits')) {
            return null;
        }

        $day = ($leg->start_at ?? Carbon::now())->copy()->startOfDay();

        $covering = TravelPermit::query()
            ->where('vehicle_id', $carId)
            ->where('driver_id', $driverId)
            ->whereDate('valid_from', '<=', $day)
            ->whereDate('valid_to', '>=', $day)
            ->exists();

        if ($covering) {
            return null;
        }

        // The nearest thing he does hold for this car, to say why it does not count.
        $latest = TravelPermit::query()
            ->where('vehicle_id', $carId)
            ->where('driver_id', $driverId)
            ->orderByDesc('valid_to')
            ->first();

        $date = $day->format('d/m/Y');

        if ($latest === null) {
            return [
                'message' => __('This trip leaves Bahrain, and this driver has no travel authorization for this car.'),
                'expired' => false,
            ];
        }

        return [
            'message' => $latest->valid_to->lt($day)
                ? __('This trip leaves Bahrain on :date, and this driver\'s travel authorization for this car ended on :end.', ['date' => $date, 'end' => $latest->valid_to->format('d/m/Y')])
                : __('This trip leaves Bahrain on :date, and this driver\'s travel authorization for this car only starts on :start.', ['date' => $date, 'start' => $latest->valid_from->format('d/m/Y')]),
            'expired' => true,
        ];
    }
}
