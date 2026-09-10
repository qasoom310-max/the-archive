<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Modules\Limousine\Models\LimoBooking;

/**
 * Re-price every booking now that a cancelled trip comes off the bill.
 *
 * The fare is the sum of a booking's legs, but nothing recomputed it when a leg
 * was cancelled, and the sum counted cancelled legs anyway. Both are fixed
 * going forward — this brings the bookings already in the system into line,
 * since they would otherwise keep quoting a total that includes trips that
 * never ran until somebody happened to edit them.
 *
 * A trip cancelled too late for a refund keeps its place on the bill: that
 * money was forfeited and returned as a coupon, so it was earned here.
 *
 * Payment flags follow the new totals, because a booking can become settled
 * purely by losing the leg it was still short on.
 */
return new class extends Migration
{
    public function up(): void
    {
        LimoBooking::query()->with('legs')->chunkById(200, function ($bookings): void {
            foreach ($bookings as $booking) {
                $before = round((float) $booking->fare, 3);

                $booking->recalcTotal();

                if (round((float) $booking->fare, 3) === $before) {
                    continue;
                }

                $booking->save();
                $booking->syncPaymentFromAdvance();
            }
        });
    }

    public function down(): void
    {
        // The old totals counted trips that never ran. There is nothing worth
        // restoring them to, and recalcTotal() on the reverted code would put
        // them back anyway the next time a booking is touched.
    }
};
