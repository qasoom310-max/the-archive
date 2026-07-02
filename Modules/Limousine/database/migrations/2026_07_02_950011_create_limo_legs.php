<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trip legs shared by bookings and quotations (polymorphic `legable`). Every
 * trip is one or more legs; two service types cover every scenario and "mixed"
 * is simply several legs:
 *   - transfer  → one-way From → To at a start time.
 *   - chauffeur → a car at disposal for `hours` per day across `days` consecutive
 *                 days from the start time.
 * Each leg is priced (rate × basis − discount + VAT = net); the parent's grand
 * total is the sum of the leg nets.
 *
 * Replaces the short-lived single-parent limo_quotation_lines table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('limo_quotation_lines');

        Schema::create('limo_legs', function (Blueprint $table): void {
            $table->id();
            $table->morphs('legable'); // limo_bookings / limo_quotations
            $table->unsignedInteger('sequence')->default(0);
            $table->string('service_type')->default('transfer'); // transfer | chauffeur
            $table->string('from_location')->nullable();
            $table->string('to_location')->nullable();
            $table->dateTime('start_at')->nullable();
            $table->decimal('hours', 8, 2)->nullable();   // per day (chauffeur)
            $table->unsignedInteger('days')->default(1);  // consecutive days (chauffeur)
            $table->string('vehicle')->nullable();
            $table->string('vehicle_details')->nullable();
            $table->decimal('rate', 12, 3)->default(0);
            $table->string('rate_basis')->default('trip'); // trip | hour | day
            $table->decimal('discount', 12, 3)->default(0);
            $table->decimal('vat', 12, 3)->default(0);
            $table->decimal('line_total', 12, 3)->default(0);
            $table->decimal('net_amount', 12, 3)->default(0);
            $table->string('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('limo_legs');
    }
};
