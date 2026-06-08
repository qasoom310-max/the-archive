<?php

declare(strict_types=1);

namespace Modules\Pos\Listeners;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Modules\Pos\Events\PosOrderPaid;
use Modules\Pos\Models\PosCustomerDiscount;
use Throwable;

/**
 * Rolling renewal: when a finalised order actually used a per-phone customer
 * discount (`customer_discount_percent > 0`), push that discount's expiry to
 * (order date + 90 days). This is what makes the window "renew on every
 * purchase" — a customer who keeps buying keeps the discount alive; one who
 * goes 90 days without a sale lapses (the daily sweep flips `active` off and
 * {@see PosCustomerDiscount::findForPhone()} stops matching it meanwhile).
 *
 * Fires AFTER `PosOrder::finalizeSale()` commits. Errors are swallowed +
 * logged — a renewal hiccup must never break checkout, same convention as the
 * WhatsApp / KDS listeners. Wired in `PosServiceProvider::boot()`.
 */
final class RenewCustomerDiscount
{
    public function handle(PosOrderPaid $event): void
    {
        try {
            $order = $event->order;

            // Only renew when a discount was genuinely applied to this sale.
            // (percent > 0 ⟺ a matching, active, in-window discount was found
            // when the customer was added.)
            if ($order->customer_discount_percent <= 0) {
                return;
            }

            $phone = $order->customer_phone;

            if ($phone === null || $phone === '') {
                $partner = $order->partner;
                $phone = $partner?->phone;
            }

            if ($phone === null || $phone === '') {
                return;
            }

            $discount = PosCustomerDiscount::findForPhone($phone);

            if ($discount === null) {
                return;
            }

            $base = $order->ordered_at instanceof Carbon ? $order->ordered_at : Carbon::now();
            $discount->renewFrom($base);
        } catch (Throwable $e) {
            Log::warning('Failed to renew customer discount after sale: ' . $e->getMessage());
        }
    }
}
