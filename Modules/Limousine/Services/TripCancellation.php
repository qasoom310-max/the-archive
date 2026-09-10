<?php

declare(strict_types=1);

namespace Modules\Limousine\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCoupon;
use Modules\Limousine\Models\LimoLeg;

/**
 * Cancelling a trip, and what the customer gets back.
 *
 * The rule the office works to:
 *   · nothing paid            → just cancel, there is nothing to give back;
 *   · paid, >48h before start → a full refund is due (money back, or the same
 *     value as a coupon — the person cancelling decides);
 *   · paid, inside 48h        → no refund, but the money is not simply kept:
 *     a coupon for what they paid, good for a year from the booking.
 *
 * The window is measured from the TRIP's own start, not the booking's, because
 * legs run on different days — cancelling tomorrow's leg of a booking that
 * starts next week must be judged on tomorrow.
 */
final class TripCancellation
{
    /** Refund cut-off, in hours before the trip starts. */
    public const REFUND_WINDOW_HOURS = 48;

    /**
     * The three outcomes, named after where they are stored — on the leg. One
     * definition, because the bill reads these too when deciding whether a
     * cancelled trip is still charged for.
     */
    public const OUTCOME_NONE = LimoLeg::REFUND_NONE;

    public const OUTCOME_REFUNDED = LimoLeg::REFUND_REFUNDED;

    public const OUTCOME_COUPON = LimoLeg::REFUND_COUPON;

    /**
     * What cancelling this trip right now would mean — used to tell the user
     * before they commit, and again to decide what actually happens.
     *
     * @return array{paid: bool, amount: float, within_window: bool, hours_to_start: ?float, refund_due: bool}
     */
    public function preview(LimoLeg $leg): array
    {
        $booking = $leg->legable instanceof LimoBooking ? $leg->legable : null;

        // What the customer actually put down against this trip. A booking is
        // paid as a whole, so a leg counts as paid once the booking is settled.
        $amount = round((float) $leg->net_amount, 3);
        $paid = $booking !== null
            && $booking->payment_status === LimoBooking::PAYMENT_PAID
            && $amount > 0.001;

        $hours = null;
        if ($leg->start_at !== null) {
            // Signed, so it goes negative once the trip time has passed — which
            // still counts as inside the window, as it should.
            $hours = round(Carbon::now()->diffInHours($leg->start_at, false), 2);
        }

        // No start time at all → treat as inside the window rather than handing
        // out a refund the rule may not support.
        $withinWindow = $hours === null || $hours < self::REFUND_WINDOW_HOURS;

        return [
            'paid' => $paid,
            'amount' => $amount,
            'within_window' => $withinWindow,
            'hours_to_start' => $hours,
            'refund_due' => $paid && ! $withinWindow,
        ];
    }

    /**
     * Cancel the trip and settle what the customer gets.
     *
     * `$refundAsCoupon` only applies when a full refund is due: it lets the
     * office hand back credit instead of money. Inside the 48-hour window the
     * coupon is automatic and the flag is irrelevant.
     *
     * @return array{outcome: string, amount: float, coupon: ?LimoCoupon}
     */
    public function cancel(LimoLeg $leg, ?string $reason = null, bool $refundAsCoupon = false): array
    {
        $preview = $this->preview($leg);
        $booking = $leg->legable instanceof LimoBooking ? $leg->legable : null;

        return DB::transaction(function () use ($leg, $booking, $preview, $reason, $refundAsCoupon): array {
            $outcome = self::OUTCOME_NONE;
            $amount = 0.0;
            $coupon = null;

            if ($preview['paid']) {
                $amount = $preview['amount'];

                if ($preview['refund_due'] && ! $refundAsCoupon) {
                    // Money goes back the way it came; the transfer itself
                    // happens in the bank, this records that it is owed.
                    $outcome = self::OUTCOME_REFUNDED;
                } else {
                    // Either inside the window (no refund, so credit instead) or
                    // a full refund the office chose to give as credit.
                    $outcome = self::OUTCOME_COUPON;
                    $coupon = $this->issueCoupon($leg, $booking, $amount, $reason);
                }
            }

            $leg->status = LimoLeg::STATUS_CANCELLED;
            $leg->cancelled_at = Carbon::now();
            $leg->cancellation_reason = $reason !== null && trim($reason) !== '' ? trim($reason) : null;
            $leg->refund_outcome = $outcome;
            $leg->refund_amount = $amount;
            $leg->save();

            // The booking summarises its legs, so it follows them.
            $booking?->syncStatusFromLegs();

            // …the money included, which nothing used to do. A called-off trip
            // comes off the bill, so the fare went on quoting a trip that never
            // ran and the office was left chasing a balance the customer never
            // owed. Re-price, then let the payment flag follow the new total —
            // a booking can become settled purely by losing the leg that was
            // still owed for.
            if ($booking !== null) {
                if ($outcome === self::OUTCOME_REFUNDED) {
                    // Money handed back is no longer money held. Left alone,
                    // the booking went on showing the refunded fare as received
                    // and the reports counted it as collected.
                    $booking->advance = round(max(0.0, (float) $booking->advance - $amount), 3);
                }

                $booking->recalcTotal();
                $booking->save();
                $booking->syncPaymentFromAdvance();
                // Losing a leg changes what is owed, so the invoice follows —
                // unless money has already landed against it.
                $booking->syncInvoice();
            }

            return ['outcome' => $outcome, 'amount' => $amount, 'coupon' => $coupon];
        });
    }

    /**
     * Credit for a cancelled trip, valid a year from the booking that paid for
     * it (not from today — the customer's year runs from when they booked).
     */
    private function issueCoupon(LimoLeg $leg, ?LimoBooking $booking, float $amount, ?string $reason): LimoCoupon
    {
        // The customer's year runs from when they booked, not from today.
        $from = Carbon::now();
        if ($booking !== null && $booking->created_at !== null) {
            $from = $booking->created_at;
        }

        return LimoCoupon::query()->create([
            'code' => $this->nextCode(),
            'limo_booking_id' => $booking?->id,
            'limo_leg_id' => $leg->id,
            'leg_reference' => $leg->reference,
            'customer_id' => $booking?->customer_id,
            'amount' => $amount,
            'expires_at' => $from->copy()->addMonths(LimoCoupon::VALID_MONTHS),
            'note' => $reason,
            'user_id' => Auth::id(),
        ]);
    }

    private function nextCode(): string
    {
        $last = (int) LimoCoupon::query()->max('id');

        return sprintf('CPN-%04d', $last + 1);
    }
}
