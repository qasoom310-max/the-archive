<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Buses, per-service vehicle lists, and the widget's non-fare settings.
 *
 * Without `pricing_service_vehicles` the airport widget would offer a 50-seat
 * coach, because every car would otherwise apply to every service.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Which vehicles a service actually offers, in display order.
        Schema::create('pricing_service_vehicles', function (Blueprint $table): void {
            $table->id();
            $table->string('service_id', 32);
            $table->string('car_id', 32);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->foreign('service_id')->references('id')->on('pricing_services')->cascadeOnDelete();
            $table->foreign('car_id')->references('id')->on('pricing_cars')->cascadeOnDelete();
            $table->unique(['service_id', 'car_id'], 'pricing_service_vehicles_unique');
        });

        // Everything the widget shows that isn't a fare. One row per database.
        Schema::create('pricing_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('whatsapp', 32)->default('');
            // Minimum notice before a trip can be booked online.
            $table->unsignedSmallInteger('lead_hours')->default(12);
            $table->timestamps();
        });

        Schema::table('pricing_services', function (Blueprint $table): void {
            // These fares are assumptions, not the owner's prices. Drives a
            // warning on the admin screen; never leaves the ERP.
            $table->boolean('estimated')->default(false)->after('return_factor');
        });

        // Luggage on a coach depends on the group, and an invented number is
        // worse than none — so buses carry null rather than a guess.
        Schema::table('pricing_cars', function (Blueprint $table): void {
            $table->unsignedSmallInteger('bags')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('pricing_cars', function (Blueprint $table): void {
            $table->unsignedSmallInteger('bags')->default(0)->nullable(false)->change();
        });

        Schema::table('pricing_services', function (Blueprint $table): void {
            $table->dropColumn('estimated');
        });

        Schema::dropIfExists('pricing_settings');
        Schema::dropIfExists('pricing_service_vehicles');
    }
};
