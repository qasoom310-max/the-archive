<?php

declare(strict_types=1);

namespace Modules\Pos\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Pos\Events\PosOrderPaid;
use Modules\Pos\Listeners\QueueLinesForKitchen;
use Modules\Pos\Listeners\SendPosOrderReceiptViaWhatsApp;
use Modules\Pos\Services\PosSessionManager;

/**
 * POS module provider. Loaded by the core ModuleServiceProvider only while
 * the module is installed; routes/views are auto-discovered by the engine.
 */
final class PosServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PosSessionManager::class);
    }

    public function boot(): void
    {
        // Auto-receipt: every finalised POS order fires PosOrderPaid; the
        // listener queues a WhatsApp template message if the order has a
        // captured customer phone. Registered here (not in EventServiceProvider)
        // because the listener depends on the WhatsApp module's service —
        // we want this binding only when POS is actually installed.
        Event::listen(PosOrderPaid::class, [SendPosOrderReceiptViaWhatsApp::class, 'handle']);

        // KDS routing: for each finalised order, stamp prep_status on lines
        // whose product's category has a `station` set so the Kitchen /
        // Shisha screens pick them up on next poll.
        Event::listen(PosOrderPaid::class, [QueueLinesForKitchen::class, 'handle']);
    }
}
