<?php

declare(strict_types=1);

namespace Modules\Pos\Listeners;

use Modules\Pos\Events\PosOrderPaid;
use Modules\Pos\Services\KitchenRouter;

/**
 * Safety net on the paid event: route any not-yet-sent kitchen / shisha
 * lines to the KDS. In the normal postpaid dine-in flow every item was
 * already auto-sent at the terminal as it was added (see
 * {@see \Modules\Pos\Livewire\PosTerminal::addProduct()}), so this is a
 * no-op — but it still covers a sale finalised without passing through the
 * terminal's add path (e.g. an order built programmatically).
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
