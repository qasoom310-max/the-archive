<?php

declare(strict_types=1);

namespace Modules\Pos\Listeners;

use Modules\Pos\Events\PosOrderPaid;
use Modules\Pos\Services\KitchenRouter;

/**
 * Routes any not-yet-sent kitchen / shisha lines to the KDS on the paid
 * event. Its role depends on the POS mode:
 *  - PREPAID (the default): this is the PRIMARY router — items are NOT sent
 *    while the cart is built, so payment is what fires the order to the
 *    kitchen.
 *  - POSTPAID dine-in: every item was already auto-sent at the terminal as it
 *    was added (see {@see \Modules\Pos\Livewire\PosTerminal::addProduct()}),
 *    so this is a no-op safety net — but it still covers a sale finalised
 *    without passing through the terminal's add path (e.g. built
 *    programmatically).
 *
 * Idempotent (delegates to {@see KitchenRouter}, which skips already-routed
 * lines). Errors never block checkout — same convention as the WhatsApp
 * listener. Wired in `PosServiceProvider::boot()`.
 */
final class QueueLinesForKitchen
{
    public function handle(PosOrderPaid $event): void
    {
        $order = $event->order;
        $queued = app(KitchenRouter::class)->route($order);

        if ($queued > 0) {
            $order->logChange("Sent {$queued} item(s) to the kitchen / shisha screens.");
        }
    }
}
