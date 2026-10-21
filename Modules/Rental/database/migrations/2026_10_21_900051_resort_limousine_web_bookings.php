<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Modules\Rental\Models\RentalWebBooking;

/**
 * Plugin 1.1.0 told the ERP "rental" for every product but the fare finder,
 * so the website's "Limousine Service" product arrived as car rentals. The
 * rule now lets the line's name decide over that default; this sorts the
 * bookings already received the same way.
 *
 * Only rows a person has not switched by hand and that have not yet become a
 * rental order or a limousine booking are touched, and only rental → limousine.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rental_web_bookings') || ! Schema::hasColumn('rental_web_bookings', 'service_manual')) {
            return;
        }

        DB::table('rental_web_bookings')
            ->where('service', RentalWebBooking::SERVICE_RENTAL)
            ->where('service_manual', false)
            ->whereNull('rental_order_id')
            ->whereNull('limo_booking_id')
            ->orderBy('id')
            ->get(['id', 'source_reference', 'product', 'payload'])
            ->each(static function (object $row): void {
                $payload = json_decode((string) ($row->payload ?? ''), true);
                $service = RentalWebBooking::classify(is_array($payload) ? $payload : [], $row->product);

                if ($service !== RentalWebBooking::SERVICE_LIMOUSINE) {
                    return;
                }

                DB::table('rental_web_bookings')->where('id', $row->id)->update(['service' => $service]);
                Log::info('Web booking '.$row->source_reference.' moved from car rental to limousine.');
            });
    }

    public function down(): void
    {
        // A sorting fix; nothing to undo.
    }
};
