<?php

declare(strict_types=1);

namespace Modules\Limousine\Services;

use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Support\TripDetailsLock;

/**
 * A trip as the staff app receives it.
 *
 * Built on the bookings list's own row ({@see LimoQueueRows::row()}), so the
 * phone shows exactly what the screen does — the trip-details lock included:
 * for operations staff the owner named, a trip without a car and a driver
 * comes as its time, car type, customer and countries only.
 */
final class AppTrips
{
    public function __construct(private readonly LimoQueueRows $rows)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function present(LimoLeg $leg, bool $detail = false): array
    {
        $row = $this->rows->row($leg);
        $hidden = TripDetailsLock::hides($leg);

        $missing = match (true) {
            $leg->hasCarAndDriver() => null,
            $leg->car_id === null && $leg->driver_id === null => 'car_and_driver',
            $leg->car_id === null => 'car',
            default => 'driver',
        };

        $trip = [
            'id' => $leg->id,
            'reference' => $row['reference'],
            'booking_reference' => $row['booking_reference'],
            'start_at' => $leg->start_at?->toIso8601String(),
            'start_label' => $row['from_date'],
            'status' => $leg->status,
            'type' => $row['type'],
            'customer' => $row['customer'],
            'car_type' => $row['vehicle_type'],
            'car' => $row['vehicle'] !== '' ? $row['vehicle'] : null,
            'driver' => $row['driver'] !== '' ? $row['driver'] : null,
            'car_id' => $leg->car_id,
            'driver_id' => $leg->driver_id,
            'missing' => in_array($leg->status, LimoLeg::OPEN_STATUSES, true) ? $missing : null,
            'urgency' => $leg->crewUrgency(now()),
            'details_hidden' => $hidden,
            'pickup' => $row['pickup'],
            'dropoff' => $row['dropoff'],
        ];

        if (! $detail) {
            return $trip;
        }

        $booking = $leg->legable instanceof LimoBooking ? $leg->legable : null;

        return $trip + [
            'passenger' => $hidden ? null : (trim((string) ($booking->pax_name ?? '')) ?: null),
            'passenger_phone' => $hidden ? null : (trim((string) ($booking->pax_contact ?? '')) ?: null),
            'contact_phone' => $row['contact_phone'] !== '' ? $row['contact_phone'] : null,
            'flight' => $hidden ? null : (trim((string) ($booking->flight_number ?? '')) ?: null),
            'notes' => $row['comments'] !== '' ? $row['comments'] : null,
            'pickup_map' => $hidden ? null : (trim((string) ($leg->from_location_url ?? '')) ?: null),
            'dropoff_map' => $hidden ? null : (trim((string) ($leg->to_location_url ?? '')) ?: null),
            // The message the office copies to the driver — empty while held back.
            'copy_text' => $this->rows->whatsappText($leg),
        ];
    }
}
