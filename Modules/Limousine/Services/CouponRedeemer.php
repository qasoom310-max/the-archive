<?php

declare(strict_types=1);

namespace Modules\Limousine\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCoupon;

/**
 * Spending a coupon against a booking.
 *
 * Credit is applied as money already received: the redeemed amount is added to
 * the booking's advance, so the balance falls and the payment flag settles
 * through the same path a cash payment takes. Nothing about "paid" needs to
 * learn a second way to become true.
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
     * Apply a coupon to a booking.
     *
     * @return array{ok: bool, error?: string, applied?: float, remaining?: float, coupon?: LimoCoupon}
     */
    public function apply(string $code, LimoBooking $booking): array
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

        $due = $booking->balanceDue();
        if ($due <= 0.001) {
            return ['ok' => false, 'error' => self::ERROR_NOTHING_DUE];
        }

        // Never spend more than is owed, never more than is left.
        $applied = round(min($remaining, $due), 3);

        return DB::transaction(function () use ($coupon, $booking, $applied): array {
            $coupon->redemptions()->create([
                'limo_booking_id' => $booking->id,
                'booking_reference' => $booking->reference,
                'amount' => $applied,
                'user_id' => Auth::id(),
            ]);

            // Credit counts as money taken, so the existing payment logic
            // decides "paid" exactly as it would for cash.
            $booking->advance = round((float) $booking->advance + $applied, 3);
            $booking->save();
            $booking->syncPaymentFromAdvance();

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
            self::ERROR_NOTHING_DUE => (string) __('This booking has nothing left to pay.'),
            default => (string) __('That coupon could not be applied.'),
        };
    }
}
