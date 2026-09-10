<?php

declare(strict_types=1);

namespace Modules\Rental\Models\Concerns;

/**
 * Every table that may hold a driver's id — rental jobs and limousine trips
 * alike, since both apps read the one shared `rental_drivers` table.
 *
 * @see \App\Models\Concerns\GuardsDeletionWhenReferenced
 */
trait DriverDeletionReferences
{
    /**
     * @return array<string, array{0: list<string>, 1: string}>
     */
    protected static function deletionReferences(): array
    {
        return [
            'rental_orders' => [['driver_id'], __('rental orders')],
            'rental_quotations' => [['driver_id'], __('rental quotations')],
            'limo_legs' => [['driver_id'], __('limousine trips')],
        ];
    }
}
