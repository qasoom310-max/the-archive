<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Limousine\Models\LimoBooking;

/**
 * Credit can be spent on a rental car, not only a limousine trip.
 *
 * A redemption pointed at `limo_booking_id`, which said the money could only
 * ever go back to the business that issued it. The customer does not see two
 * businesses — they were owed for a journey and want their car — so what a
 * redemption is against becomes polymorphic.
 *
 * The old column stays and is still written for limousine bookings, because
 * plenty of code and history reads it; the morph columns are what the redeemer
 * fills in from now on, and the existing rows are backfilled so the two agree.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('limo_coupon_redemptions', function (Blueprint $table): void {
            $table->string('redeemable_type')->nullable()->after('limo_coupon_id');
            $table->unsignedBigInteger('redeemable_id')->nullable()->after('redeemable_type');
            $table->index(['redeemable_type', 'redeemable_id'], 'limo_coupon_redemptions_redeemable_index');
        });

        // Everything spent so far went on a limousine booking, by definition —
        // there was nowhere else it could go.
        DB::table('limo_coupon_redemptions')
            ->whereNotNull('limo_booking_id')
            ->update([
                'redeemable_type' => LimoBooking::class,
                'redeemable_id' => DB::raw('limo_booking_id'),
            ]);
    }

    public function down(): void
    {
        Schema::table('limo_coupon_redemptions', function (Blueprint $table): void {
            $table->dropIndex('limo_coupon_redemptions_redeemable_index');
            $table->dropColumn(['redeemable_type', 'redeemable_id']);
        });
    }
};
