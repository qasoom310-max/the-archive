<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A driver per trip leg.
 *
 * Legs are dispatched one at a time — Friday's airport run and Sunday's return
 * are separate jobs — so the driver belongs on the leg beside the car, not on
 * the booking. `driver_id` is a logical ref to the SHARED `rental_drivers`
 * table (see LimoDriver): both apps dispatch the same people.
 *
 * `driver` holds the name at the time of assignment, the same snapshot trick
 * `vehicle` uses: a leg should still say who drove it after the driver leaves
 * and their record is deactivated or renamed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('limo_legs', function (Blueprint $table): void {
            $table->unsignedBigInteger('driver_id')->nullable()->after('car_id');
            $table->string('driver')->nullable()->after('driver_id');
            $table->index('driver_id');
        });
    }

    public function down(): void
    {
        // SQLite refuses to drop an indexed column, so the index goes first in
        // its own statement.
        Schema::table('limo_legs', function (Blueprint $table): void {
            $table->dropIndex(['driver_id']);
        });

        Schema::table('limo_legs', function (Blueprint $table): void {
            $table->dropColumn(['driver_id', 'driver']);
        });
    }
};
