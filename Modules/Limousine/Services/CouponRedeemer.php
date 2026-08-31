<?php

declare(strict_types=1);

namespace Modules\Limousine\Services;

use App\Erp\Contracts\TakesCouponCredit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCoupon;

/**
 * Spending a coupon against whatever it is being put towards.
 *
 * A limousine booking or a rental order — anything that {@see TakesCouponCredit},
 * because credit earned on a cancelled trip is the customer's money and they may
 * well want a car with it. The redeemer does not care which: it asks what is
 * owed, puts credit against it, and records where it went.
 *
 * Credit is applied as money already RECEIVED: the amount lands on the advance,
 * so the balance falls and the payment flag settles through the same path a cash
 * payment takes. Nothing about "paid" learns a second way to become true.
 *
 * Only ever as much as is needed and as much as is left, whichever is smaller —
 * so a 100 BD coupon against a 15 BD trip spends 15 and keeps 85 for next time,
 * and a 25 BD coupon against a 40 BD trip leaves 15 to collect.
 */
final class CouponRedeemer
{
    public const ERROR_NOT_FOUND = 'not_found';

    public const ERROR_EXPIRED = 'expired';

    public const ERROR_EMPTY = 'empty';

    public const ERROR_NOTHING_DUE = 'nothing_due';

    /**
     * Apply a coupon to a booking, a rental order, or anything else that can
     * hold credit.
     *
     * @return array{ok: bool, error?: string, applied?: float, remaining?: float, coupon?: LimoCoupon}
     */
    public function apply(string $code, Model&TakesCouponCredit $target): array
    {
        $coupon = LimoCoupon::query()->where('code', trim($code))->first();

        if ($coupon === null) {
            return ['ok' => false, 'error' => self::ERROR_NOT_FOUND];
        }

        if ($coupon->isExpired()) {
            return ['ok' => false, 'error' => self::ERROR_EXPIRED];
        }

        $remaining = $coupon->remaining();
        if ($remaining <= 0.001) {
            return ['ok' => false, 'error' => self::ERROR_EMPTY];
        }

        $due = $target->couponBalanceDue();
        if ($due <= 0.001) {
            return ['ok' => false, 'error' => self::ERROR_NOTHING_DUE];
        }

        // Never spend more than is owed, never more than is left.
        $applied = round(min($remaining, $due), 3);

        return DB::transaction(function () use ($coupon, $target, $applied): array {
            $coupon->redemptions()->create([
                // getMorphClass, not ::class — a morph map would otherwise be
                // written round in the long form and read back short.
                'redeemable_type' => $target->getMorphClass(),
                'redeemable_id' => $target->getKey(),
                // Still written for a limousine booking: existing history and
                // reports read this column, and it costs nothing to keep true.
                'limo_booking_id' => $target instanceof LimoBooking ? $target->getKey() : null,
                'booking_reference' => $target->couponReference(),
                'amount' => $applied,
                'user_id' => Auth::id(),
            ]);

            // Credit counts as money taken, so whatever it went on settles its
            // own payment state exactly as it would for cash.
            $target->applyCouponCredit($applied);

            return [
                'ok' => true,
                'applied' => $applied,
                'remaining' => $coupon->fresh()?->remaining() ?? 0.0,
                'coupon' => $coupon,
            ];
        });
    }

    /** Human reason a code was refused, for the form to show. */
    public function errorMessage(string $error): string
    {
        return match ($error) {
            self::ERROR_NOT_FOUND => (string) __('No coupon with that code.'),
            self::ERROR_EXPIRED => (string) __('That coupon has expired.'),
            self::ERROR_EMPTY => (string) __('That coupon has no credit left.'),
            self::ERROR_NOTHING_DUE => (string) __('There is nothing left to pay on this.'),
            default => (string) __('That coupon could not be applied.'),
        };
    }
}
