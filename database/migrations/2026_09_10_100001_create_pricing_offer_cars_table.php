<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which of a service's cars one offer applies to. Not every car in a service
 * gets the same discount — an owner may want 25% off the Sedan and SUV on
 * Airport Transfer without touching the Luxury fare.
 *
 * No car rows for an offer means "not configured yet"; the admin screen and
 * the published payload both fall back to every car the service offers, the
 * same rule `pricing_service_vehicles` already uses for a service with no
 * vehicle list — see `PricingManager::cars()` / `PricingPayload::service()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing_offer_cars', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('offer_id');
            $table->string('car_id', 32);
            $table->timestamps();

            $table->foreign('offer_id')->references('id')->on('pricing_offers')->cascadeOnDelete();
            $table->foreign('car_id')->references('id')->on('pricing_cars')->cascadeOnDelete();
            $table->unique(['offer_id', 'car_id'], 'pricing_offer_cars_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_offer_cars');
    }
};
