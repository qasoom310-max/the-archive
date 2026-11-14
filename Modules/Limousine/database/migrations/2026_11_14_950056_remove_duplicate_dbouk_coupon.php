<?php

declare(strict_types=1);

use App\Erp\Activity\ActivityLogger;
use App\Erp\Backup\DatabaseBackup;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoLeg;

/**
 * One payment came back as two coupons for MR.DBOUK MOHAMMED (CPN-0003 for
 * trip 26159 and CPN-0004 for trip 26262, 48 BD each) — the bug fixed in
 * TripCancellation the same day. The owner asked for the second removed.
 *
 * Touches only that exact coupon: code CPN-0004, trip 26262, 48 BD, a customer
 * named "…DBOUK…", nothing spent from it, and CPN-0003 still there for the same
 * customer. Anything else (another database, a different coupon) is left alone.
 * Its trip becomes a plain cancellation (nothing handed back), so it comes off
 * the bill again.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('limo_coupons') || ! Schema::hasTable('rental_customers')) {
            return;
        }

        $coupon = DB::table('limo_coupons as c')
            ->join('rental_customers as rc', 'rc.id', '=', 'c.customer_id')
            ->where('c.code', 'CPN-0004')
            ->where('c.leg_reference', '26262')
            ->whereRaw('UPPER(rc.name) LIKE ?', ['%DBOUK%'])
            ->select('c.*')
            ->first();
        if ($coupon === null || abs((float) $coupon->amount - 48) > 0.001) {
            return;
        }

        $spent = Schema::hasTable('limo_coupon_redemptions')
            && DB::table('limo_coupon_redemptions')->where('limo_coupon_id', $coupon->id)->exists();
        $twin = DB::table('limo_coupons')
            ->where('code', 'CPN-0003')
            ->where('customer_id', $coupon->customer_id)
            ->exists();
        if ($spent || ! $twin) {
            Log::warning('Left coupon CPN-0004 alone', ['spent' => $spent, 'twin' => $twin]);

            return;
        }

        app(DatabaseBackup::class)->snapshot();

        DB::transaction(function () use ($coupon): void {
            DB::table('limo_coupons')->where('id', $coupon->id)->delete();

            $leg = $coupon->limo_leg_id !== null ? LimoLeg::query()->find($coupon->limo_leg_id) : null;
            if ($leg !== null && $leg->status === LimoLeg::STATUS_CANCELLED && $leg->refund_outcome === LimoLeg::REFUND_COUPON) {
                $leg->refund_outcome = LimoLeg::REFUND_NONE;
                $leg->refund_amount = 0.0;
                $leg->saveQuietly();

                $booking = $leg->legable;
                if ($booking instanceof LimoBooking) {
                    $booking->recalcTotal();
                    $booking->save();
                    $booking->syncPaymentFromAdvance();
                    $booking->syncInvoice();
                }
            }
        });

        Log::info('Removed duplicate coupon CPN-0004 (trip 26262, 48 BD)', ['coupon' => (array) $coupon]);
        app(ActivityLogger::class)->log(
            'deleted',
            'Coupon CPN-0004',
            'Duplicate of CPN-0003 for the same 48 BD payment (trip 26262); removed at the owner\'s request',
        );
    }

    public function down(): void
    {
        // A removed duplicate is not put back.
    }
};
