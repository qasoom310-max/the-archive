<?php

declare(strict_types=1);

namespace Modules\Rental\Services;

use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoLeg;
use Modules\Rental\Models\RentalOrder;

/**
 * Everything a driver has been given to do — both businesses, one list.
 *
 * The same people drive limousine trips and take out rental cars, and the
 * question the office asks about one of them is never "what did they do in
 * Limousine?" but "what have they been doing?". Two lists on two screens is the
 * same question asked twice and answered half.
 *
 * Read straight from each app's own records rather than kept in a table of its
 * own: a job history that is written separately is a second copy to be wrong.
 *
 * @phpstan-type JobRow array{
 *     kind: string, type: string, reference: string, at: ?\Illuminate\Support\Carbon,
 *     given_by: string, car: string, status: string, url: string
 * }
 */
final class DriverJobHistory
{
    /**
     * Every job for this driver, most recent first.
     *
     * @return list<JobRow>
     */
    public function for(int $driverId, int $limit = 50): array
    {
        $rows = [...$this->trips($driverId), ...$this->rentals($driverId)];

        // One order across both sources, so the list reads as a working life
        // rather than two lists stacked. Undated jobs sink rather than sorting
        // as if they happened in 1970.
        usort($rows, static function (array $a, array $b): int {
            if ($a['at'] === null && $b['at'] === null) {
                return 0;
            }
            if ($a['at'] === null) {
                return 1;
            }
            if ($b['at'] === null) {
                return -1;
            }

            return $b['at'] <=> $a['at'];
        });

        return array_slice($rows, 0, $limit);
    }

    /**
     * Limousine trips — per LEG, because a leg is what is dispatched and what
     * a driver is actually given.
     *
     * @return list<JobRow>
     */
    private function trips(int $driverId): array
    {
        return LimoLeg::query()
            ->where('driver_id', $driverId)
            ->with('legable')
            ->orderByDesc('start_at')
            ->limit(50)
            ->get()
            ->map(function (LimoLeg $leg): array {
                $booking = $leg->legable instanceof LimoBooking ? $leg->legable : null;

                return [
                    'kind' => 'limousine',
                    'type' => (string) __('Limousine trip'),
                    'reference' => (string) ($leg->reference ?? ''),
                    'at' => $leg->start_at,
                    // Who handed it out: whoever raised the booking.
                    'given_by' => (string) ($booking->prepared_by ?? ''),
                    'car' => (string) ($leg->vehicle ?? ''),
                    'status' => (string) ($leg->status ?? ''),
                    'url' => '/app/limousine/booking/' . (int) $leg->legable_id,
                ];
            })
            ->all();
    }

    /**
     * Rental orders where this driver was the one named on the contract.
     *
     * @return list<JobRow>
     */
    private function rentals(int $driverId): array
    {
        return RentalOrder::query()
            ->where('driver_id', $driverId)
            ->with(['vehicle:id,name,plate_number', 'createdBy:id,name'])
            ->orderByDesc('start_date')
            ->limit(50)
            ->get()
            ->map(function (RentalOrder $order): array {
                return [
                    'kind' => 'rental',
                    'type' => (string) __('Rental car'),
                    'reference' => (string) ($order->reference ?? ''),
                    'at' => $order->start_date,
                    'given_by' => (string) ($order->createdBy->name ?? ''),
                    'car' => (string) ($order->vehicle?->displayName() ?? ''),
                    'status' => (string) ($order->state ?? ''),
                    'url' => '/app/rental/order/' . (int) $order->id,
                ];
            })
            ->all();
    }
}
