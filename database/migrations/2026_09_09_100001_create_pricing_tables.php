<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Published fares — the single place a price for a service exists.
 *
 * Today the same airport trip is priced differently across WooCommerce
 * products, page copy, a plugin's built-in table and whatever staff quote on
 * WhatsApp. These tables become the only source; the website reads them over
 * the pricing API and caches the answer.
 *
 * Core migration, so it lands in Main AND in every workspace via
 * `workspaces:migrate` — each business keeps its own fares, and the version
 * counter is naturally per-database.
 */
return new class extends Migration
{
    public function up(): void
    {
        // String primary keys (`sedan`, `airport`) rather than autoincrements:
        // the website and the WordPress plugin quote these ids back, so they
        // must be stable and readable, and must never be renumbered.
        Schema::create('pricing_cars', function (Blueprint $table): void {
            $table->string('id', 32)->primary();
            $table->string('name_en', 80);
            $table->string('name_ar', 80);
            $table->string('model', 120);
            $table->unsignedSmallInteger('pax')->default(0);
            $table->unsignedSmallInteger('bags')->default(0);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('pricing_services', function (Blueprint $table): void {
            $table->string('id', 32)->primary();
            $table->string('name_en', 120);
            $table->string('name_ar', 120);
            $table->string('ask_en', 160);
            $table->string('ask_ar', 160);
            $table->text('note_en')->nullable();
            $table->text('note_ar')->nullable();
            // A return costs the one-way × this. 2.00 = two full one-ways,
            // 1.80 = a 10% discount on the second leg. Null = no return offered.
            $table->decimal('return_factor', 3, 2)->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('pricing_options', function (Blueprint $table): void {
            $table->id();
            $table->string('service_id', 32);
            $table->string('code', 48);
            $table->string('label_en', 255);
            $table->string('label_ar', 255);
            // What fits in a dropdown, where the full label is a list of areas.
            $table->string('short_en', 160)->nullable();
            $table->string('short_ar', 160)->nullable();
            // For hour-block options — lets the site show a per-hour figure.
            $table->unsignedSmallInteger('hours')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->foreign('service_id')->references('id')->on('pricing_services')->cascadeOnDelete();
            $table->unique(['service_id', 'code'], 'pricing_options_service_code_unique');
        });

        Schema::create('pricing_rates', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('option_id');
            $table->string('car_id', 32);
            // The dinar is 1000 fils, so three decimals is the correct storage
            // precision even though every fare today is a whole number — a
            // percentage discount has to land somewhere exact.
            $table->decimal('amount', 8, 3);
            $table->timestamps();

            $table->foreign('option_id')->references('id')->on('pricing_options')->cascadeOnDelete();
            $table->foreign('car_id')->references('id')->on('pricing_cars')->cascadeOnDelete();
            $table->unique(['option_id', 'car_id'], 'pricing_rates_option_car_unique');
        });

        // Charged per hour past a booked block. Chauffeur only today, but the
        // shape is per (service, car) so another service can gain one.
        Schema::create('pricing_extra_hours', function (Blueprint $table): void {
            $table->id();
            $table->string('service_id', 32);
            $table->string('car_id', 32);
            $table->decimal('amount', 8, 3);
            $table->timestamps();

            $table->foreign('service_id')->references('id')->on('pricing_services')->cascadeOnDelete();
            $table->foreign('car_id')->references('id')->on('pricing_cars')->cascadeOnDelete();
            $table->unique(['service_id', 'car_id'], 'pricing_extra_hours_service_car_unique');
        });

        Schema::create('pricing_offers', function (Blueprint $table): void {
            $table->id();
            $table->string('service_id', 32);
            $table->boolean('active')->default(false);
            $table->decimal('percent', 5, 2)->default(0);
            $table->string('label_en', 120)->nullable();
            $table->string('label_ar', 120)->nullable();
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->timestamps();

            $table->foreign('service_id')->references('id')->on('pricing_services')->cascadeOnDelete();
            $table->unique('service_id', 'pricing_offers_service_unique');
        });

        // One row, one integer. Every write to the tables above bumps it once,
        // inside the same transaction — the website uses it as an ETag, so it
        // must change exactly when the published fares change.
        Schema::create('pricing_version', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('version')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Children before parents: the FKs above are cascade-on-delete, but the
        // drop order still has to respect them on MySQL.
        Schema::dropIfExists('pricing_version');
        Schema::dropIfExists('pricing_offers');
        Schema::dropIfExists('pricing_extra_hours');
        Schema::dropIfExists('pricing_rates');
        Schema::dropIfExists('pricing_options');
        Schema::dropIfExists('pricing_services');
        Schema::dropIfExists('pricing_cars');
    }
};
