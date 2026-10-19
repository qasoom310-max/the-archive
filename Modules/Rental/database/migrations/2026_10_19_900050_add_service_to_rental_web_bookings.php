<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Rental\Models\RentalWebBooking;

/**
 * A website booking is either a car HIRE or a LIMOUSINE trip. The website sells
 * both, and they become different records here: a rental order, or a limousine
 * booking with a trip in the queue.
 *
 * `service_manual` remembers that a person chose the service, so a later
 * delivery from the website cannot flip it back. `limo_booking_id` is the
 * limousine booking raised from it, a logical ref like `rental_order_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rental_web_bookings')) {
            return;
        }

        Schema::table('rental_web_bookings', function (Blueprint $table): void {
            $table->string('service', 20)->default('rental')->index()->after('source_reference');
            $table->boolean('service_manual')->default(false)->after('service');
            $table->unsignedBigInteger('limo_booking_id')->nullable()->index()->after('rental_order_id');
        });

        // The bookings already received, sorted by what they asked for.
        DB::table('rental_web_bookings')->orderBy('id')->get(['id', 'product', 'payload'])
            ->each(static function (object $row): void {
                $payload = json_decode((string) ($row->payload ?? ''), true);

                DB::table('rental_web_bookings')->where('id', $row->id)->update([
                    'service' => RentalWebBooking::classify(is_array($payload) ? $payload : [], $row->product),
                ]);
            });
    }

    public function down(): void
    {
        if (! Schema::hasTable('rental_web_bookings')) {
            return;
        }

        Schema::table('rental_web_bookings', function (Blueprint $table): void {
            $table->dropIndex(['service']);
        });
        Schema::table('rental_web_bookings', function (Blueprint $table): void {
            $table->dropIndex(['limo_booking_id']);
        });
        Schema::table('rental_web_bookings', function (Blueprint $table): void {
            $table->dropColumn(['service', 'service_manual', 'limo_booking_id']);
        });
    }
};
