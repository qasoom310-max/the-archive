<?php

declare(strict_types=1);

namespace Modules\Rental\Models\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Derives a transport customer's service usage (Rental / Limousine / Both) from
 * their actual bookings, so the tag never needs maintaining. Shared by the two
 * models that sit on the `rental_customers` table — {@see \Modules\Rental\Models\RentalCustomer}
 * and {@see \Modules\Limousine\Models\LimoCustomer}.
 *
 * Cross-app reads go through `DB::table()` behind `Schema::hasTable()` guards so
 * each app keeps working when the other isn't installed.
 */
trait DerivesServiceTag
{
    /** Whether this customer has any rental order or quotation. */
    public function usesRental(): bool
    {
        $id = $this->getKey();

        return $id !== null
            && Schema::hasTable('rental_orders')
            && (DB::table('rental_orders')->where('customer_id', $id)->exists()
                || (Schema::hasTable('rental_quotations') && DB::table('rental_quotations')->where('customer_id', $id)->exists()));
    }

    /** Whether this customer has any limousine booking or quotation. */
    public function usesLimousine(): bool
    {
        $id = $this->getKey();

        return $id !== null
            && Schema::hasTable('limo_bookings')
            && (DB::table('limo_bookings')->where('customer_id', $id)->exists()
                || (Schema::hasTable('limo_quotations') && DB::table('limo_quotations')->where('customer_id', $id)->exists()));
    }

    /** Derived service usage: both | rental | limousine | none. */
    public function getServiceTagAttribute(): string
    {
        $rental = $this->usesRental();
        $limo = $this->usesLimousine();

        return match (true) {
            $rental && $limo => 'both',
            $rental => 'rental',
            $limo => 'limousine',
            default => 'none',
        };
    }

    /** Human label for the list column (— when the customer has booked nothing). */
    public function getServiceTagLabelAttribute(): string
    {
        return match ($this->getServiceTagAttribute()) {
            'both' => __('Both'),
            'rental' => __('Rent A Car'),
            'limousine' => __('Limousine'),
            default => '—',
        };
    }
}
