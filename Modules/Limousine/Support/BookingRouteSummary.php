<?php

declare(strict_types=1);

namespace Modules\Limousine\Support;

use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoLeg;

/**
 * What one line of a bookings report says about the job: where, which car type,
 * who drove. Read from the TRIPS — a booking carries them per trip, and the
 * old booking-level fields are empty on everything entered in this ERP, so the
 * report used to print "— → —" for every booking. A held-back trip
 * ({@see TripDetailsLock}) gives only its countries, as on every other screen.
 */
final class BookingRouteSummary
{
    /**
     * @return array{from: string, to: string, more: int, car_type: string, driver: string}
     */
    public static function of(LimoBooking $booking): array
    {
        $legs = $booking->relationLoaded('legs') ? $booking->legs : $booking->legs()->get();
        $first = $legs->first();

        if (! $first instanceof LimoLeg) {
            // Bookings from before trips existed kept the route on themselves.
            return [
                'from' => (string) ($booking->pickupLocation->name ?? ''),
                'to' => (string) ($booking->dropoffLocation->name ?? ''),
                'more' => 0,
                'car_type' => (string) ($booking->car_type ?? ''),
                'driver' => (string) ($booking->driver_name ?? ''),
            ];
        }

        $hidden = TripDetailsLock::hides($first);
        $drivers = $legs->map(static fn (LimoLeg $leg): string => trim((string) ($leg->driver ?? '')))
            ->filter()->unique()->implode(', ');

        return [
            'from' => $hidden ? TripDetailsLock::place($first->from_country) : (string) ($first->from_location ?? ''),
            'to' => $hidden ? TripDetailsLock::place($first->to_country) : (string) ($first->to_location ?? ''),
            'more' => max(0, $legs->count() - 1),
            'car_type' => (string) ($first->vehicle_details ?? $booking->car_type ?? ''),
            'driver' => $drivers !== '' ? $drivers : (string) ($booking->driver_name ?? ''),
        ];
    }
}
