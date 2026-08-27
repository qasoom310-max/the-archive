<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mark already-settled bookings as paid.
 *
 * The advance used to be a number nobody read: taking the full fare at the
 * counter left the booking reading "unpaid" until somebody also pressed Mark
 * paid. `LimoBooking::syncPaymentFromAdvance()` fixes that going forward, but it
 * runs when a booking is SAVED — so every booking already on file would have
 * stayed wrong until someone opened and re-saved it one by one.
 *
 * This corrects them in place, with the same rule: nothing owed means paid.
 *
 * Only ever upgrades unpaid → paid. It never un-marks anything, so a booking
 * settled through an invoice receipt (money that never went through the advance
 * field) is untouched, and re-running this is harmless.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('limo_bookings')) {
            return;
        }

        DB::table('limo_bookings')
            ->where('payment_status', 'unpaid')
            ->where('fare', '>', 0)
            // A tolerance, not `>=`: fare and advance are decimals, and an
            // exact-change booking must not be left owing a rounding crumb.
            ->whereRaw('advance >= fare - 0.005')
            ->update(['payment_status' => 'paid']);
    }

    public function down(): void
    {
        // Deliberately irreversible: once corrected there is no record of which
        // rows this touched, and guessing would un-mark genuinely paid bookings.
    }
};
