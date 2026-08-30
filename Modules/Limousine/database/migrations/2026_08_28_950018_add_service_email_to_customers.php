<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A second address for a corporate customer: where SERVICE notices go.
 *
 * At a company the person who books the car is rarely the person who chases
 * whether it turned up — accounts requests it, operations follows it. Sending
 * "the driver has reached your guest" to the booking address means it lands with
 * someone who does not act on it, so a company gets two: the general address it
 * is already known by, and this one for the trip-by-trip notices.
 *
 * Shared table (`rental_customers` serves both Rent A Car and Limousine), so the
 * column is simply available to both apps.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_customers', function (Blueprint $table): void {
            $table->string('service_email')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('rental_customers', function (Blueprint $table): void {
            $table->dropColumn('service_email');
        });
    }
};
