<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a driver records on his trip link: a dashboard photo (KM + fuel) and
 * his location when he starts, and the same when he ends. One row per trip
 * and driver. The photo is saved first (started_at / ended_at still empty) so
 * a dropped connection between the photo and the button loses nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('limo_trip_runs')) {
            return;
        }

        Schema::create('limo_trip_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('leg_id');
            $table->unsignedBigInteger('driver_id')->index();
            $table->unsignedBigInteger('car_id')->nullable();
            foreach (['start', 'end'] as $stage) {
                $table->timestamp($stage === 'start' ? 'started_at' : 'ended_at')->nullable();
                $table->string($stage . '_photo')->nullable();
                $table->json($stage . '_read')->nullable();
                $table->unsignedInteger($stage . '_km')->nullable();
                $table->unsignedTinyInteger($stage . '_fuel')->nullable();
                $table->decimal($stage . '_lat', 10, 7)->nullable();
                $table->decimal($stage . '_lng', 10, 7)->nullable();
                $table->unsignedInteger($stage . '_accuracy')->nullable();
            }
            $table->json('flags')->nullable();
            $table->timestamp('customer_notified_at')->nullable();
            $table->timestamps();

            $table->unique(['leg_id', 'driver_id'], 'limo_trip_run_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('limo_trip_runs');
    }
};
