<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The website's credential for sending bookings in, one row per database.
 *
 * Deliberately SEPARATE from the limousine portal's secret rather than shared.
 * They are two plugins on the same site doing different jobs, and a booking
 * plugin that is compromised must not also be able to sign "this trip has been
 * paid for" against the payment callback.
 *
 * `enabled` defaults false: an endpoint that accepts bookings from the open
 * internet stays shut until somebody turns it on deliberately.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_portal_configuration', function (Blueprint $table): void {
            $table->id();

            // TEXT holding APP_KEY-encrypted ciphertext, as every other
            // integration secret in this app is stored.
            $table->text('shared_secret')->nullable();
            $table->boolean('enabled')->default(false);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_portal_configuration');
    }
};
