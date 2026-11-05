<?php

declare(strict_types=1);

namespace Modules\Rental\Services;

use App\Erp\Activity\ActivityLogger;
use App\Erp\Business\Features;
use Illuminate\Support\Facades\Schema;
use Modules\Limousine\Services\WebBookingToLimousine;
use Modules\Rental\Models\RentalWebBooking;

/**
 * Moves a website booking to where the desk works, the moment it arrives: a
 * limousine trip into Limousine → Bookings (queue, no car or driver yet), a car
 * hire into Rent A Car → Orders (draft, no car yet). A booking that sat on the
 * Web bookings page went unnoticed: a customer booked and nobody knew.
 *
 * Three kinds are NOT moved:
 *  - A payment-link order (the "Limousine Service" product the service-order
 *    plugin uses). Its trip was made in the ERP first and already sits in
 *    Limousine → Bookings; moving it would book the trip twice. It is marked
 *    complete instead, so it stops swelling the pending list.
 *  - A card payment that has not gone through (Tap and the like, unpaid). That
 *    is an abandoned or failed attempt, and a customer who retries makes a
 *    second order for the same trip. It moves when the payment arrives (the
 *    website sends the order again on payment). "Pay later" moves at once.
 *  - A limousine trip in a database that does not run Limousine: it stays on
 *    the Web bookings page rather than becoming a car hire.
 */
final class WebBookingAutoTransfer
{
    public const MOVED_LIMOUSINE = 'limousine';

    public const MOVED_RENTAL = 'rental';

    public const PAYMENT_LINK = 'payment_link';

    public const AWAITING_PAYMENT = 'awaiting_payment';

    public const LEFT = 'left';

    /** Words in the website's payment-method title that mean "paid by card online". */
    private const ONLINE_METHODS = '/\b(tap|card|credit|debit|apple\s*pay|google\s*pay|benefit|knet|mada|visa|mastercard|online)\b/i';

    public function __construct(private readonly WebBookingConverter $rental)
    {
    }

    /** What happened to the booking: one of the constants above. */
    public function handle(RentalWebBooking $web): string
    {
        if (! $web->isPending() || $web->isConverted()) {
            return self::LEFT;
        }

        if ($web->isPaymentLink()) {
            $web->status = RentalWebBooking::STATUS_COMPLETE;
            $web->completed_at = now();
            $web->save();

            return self::PAYMENT_LINK;
        }

        if (self::awaitingOnlinePayment($web)) {
            return self::AWAITING_PAYMENT;
        }

        if ($web->isLimousine() && ! self::limousineRuns()) {
            return self::LEFT;
        }

        // Claim it first: the website can deliver the same order twice in
        // quick succession, and only one delivery may make the booking.
        $claimed = RentalWebBooking::query()
            ->whereKey($web->id)
            ->where('status', RentalWebBooking::STATUS_PENDING)
            ->whereNull('rental_order_id')
            ->whereNull('limo_booking_id')
            ->update(['status' => RentalWebBooking::STATUS_COMPLETE, 'completed_at' => now()]);
        if ($claimed !== 1) {
            return self::LEFT;
        }

        try {
            if ($web->isLimousine()) {
                $booking = app(WebBookingToLimousine::class)->convert($web, null);
                app(ActivityLogger::class)->log(
                    'created',
                    __('Limousine booking') . ' ' . $booking->reference,
                    __('Booked on the website') . ' — ' . $web->source_reference,
                );

                return self::MOVED_LIMOUSINE;
            }

            $order = $this->rental->convert($web);
            app(ActivityLogger::class)->log(
                'created',
                __('Rental order') . ' ' . $order->reference,
                __('Booked on the website') . ' — ' . $web->source_reference,
            );

            return self::MOVED_RENTAL;
        } catch (\Throwable $e) {
            // Back on the list for the desk; the conversion rolled itself back.
            RentalWebBooking::query()->whereKey($web->id)
                ->update(['status' => RentalWebBooking::STATUS_PENDING, 'completed_at' => null]);
            $web->status = RentalWebBooking::STATUS_PENDING;
            $web->completed_at = null;

            throw $e;
        }
    }

    /** Chose to pay by card on the website and the payment has not gone through. */
    public static function awaitingOnlinePayment(RentalWebBooking $web): bool
    {
        return ! $web->isPaidOnline()
            && preg_match(self::ONLINE_METHODS, (string) $web->payment_mode) === 1;
    }

    private static function limousineRuns(): bool
    {
        return Schema::hasTable('limo_bookings')
            && class_exists(WebBookingToLimousine::class)
            && Features::moduleAllowed('limousine');
    }
}
