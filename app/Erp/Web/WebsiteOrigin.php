<?php

declare(strict_types=1);

namespace App\Erp\Web;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which limousine bookings and rental orders were booked on the website.
 *
 * Read from the Web bookings page's own link (`rental_web_bookings`), so it
 * covers every one raised from a website order: moved automatically on
 * arrival, or converted by hand. Nothing is stored on the booking or order.
 * The table belongs to Rent A Car, and Limousine can run without it, so a
 * database without it simply has no website orders.
 */
final class WebsiteOrigin
{
    private const TABLE = 'rental_web_bookings';

    /**
     * @param  list<int>  $ids
     * @return array<int, true> limousine booking id => true
     */
    public static function limoBookings(array $ids): array
    {
        return self::linked('limo_booking_id', $ids);
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, true> rental order id => true
     */
    public static function rentalOrders(array $ids): array
    {
        return self::linked('rental_order_id', $ids);
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, true>
     */
    private static function linked(string $column, array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        if ($ids === [] || ! Schema::hasTable(self::TABLE) || ! Schema::hasColumn(self::TABLE, $column)) {
            return [];
        }

        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach (DB::table(self::TABLE)->whereIn($column, $chunk)->distinct()->pluck($column) as $id) {
                $out[(int) $id] = true;
            }
        }

        return $out;
    }
}
