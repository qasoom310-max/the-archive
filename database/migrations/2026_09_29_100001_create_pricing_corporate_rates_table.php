<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Corporate rates: the prices agreed with companies that have a deal with us.
 *
 * Same shape as `pricing_rates` (one amount per service option × car) with a
 * customer on top. `customer_id` NULL is the standard corporate rate every
 * company gets; a row for one company overrides it for that company. A cell
 * with no row falls back to the website fare.
 *
 * These are NEVER published to the website — `PricingPayload` reads only
 * `pricing_rates`. They are read by the staff assistant and the corporate
 * rates screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pricing_corporate_rates')) {
            return;
        }

        Schema::create('pricing_corporate_rates', function (Blueprint $table): void {
            $table->id();
            // Logical ref to a company customer (limousine customers live in
            // the shared rental_customers table). Null = every company.
            $table->unsignedBigInteger('customer_id')->nullable()->index();
            $table->unsignedBigInteger('option_id');
            $table->string('car_id', 32);
            $table->decimal('amount', 8, 3);
            $table->timestamps();

            $table->foreign('option_id')->references('id')->on('pricing_options')->cascadeOnDelete();
            $table->foreign('car_id')->references('id')->on('pricing_cars')->cascadeOnDelete();
            $table->index(['option_id', 'car_id'], 'pricing_corp_option_car');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_corporate_rates');
    }
};
