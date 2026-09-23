<?php

declare(strict_types=1);

namespace Modules\WooCommerce\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Modules\Pos\Events\PosOrderPaid;
use Modules\Pos\Models\PosProduct;
use Modules\WooCommerce\Console\TakeDownHeldBackProducts;
use Modules\WooCommerce\Models\WooCommerceConfiguration;
use Modules\WooCommerce\Services\WooCommerceService;

/**
 * WooCommerce module provider. Loaded by the core ModuleServiceProvider only
 * while installed (so the hooks below never fire on a database without the
 * module). Pushes POS product changes to the configured store:
 *
 *  - product saved   → upsert it (or unpublish if it was just deactivated)
 *  - product deleted → unpublish the remote listing
 *  - POS order paid  → re-push each sold product's stock (sale-time stock
 *    changes use decrement(), which bypasses model events, so they're synced
 *    explicitly here)
 *
 * Every hook is guarded by `isConfigured()` and the service itself no-ops when
 * unconfigured — a store that isn't set up costs nothing.
 */
final class WooCommerceServiceProvider extends ServiceProvider
{
    /** Product fields whose change is worth a re-push (skip irrelevant saves). */
    private const SYNCED_FIELDS = [
        'name', 'price', 'barcode', 'stock_on_hand', 'image_path', 'gallery_images', 'pos_category_id', 'active', 'publish_online',
    ];

    public function register(): void
    {
        $this->app->singleton(WooCommerceService::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([TakeDownHeldBackProducts::class]);
        }

        PosProduct::saved(function (PosProduct $product): void {
            if (! $this->storeReady()) {
                return;
            }

            // Auto-save fires per keystroke — only react to a meaningful change.
            if (! $product->wasRecentlyCreated && ! $product->wasChanged(self::SYNCED_FIELDS)) {
                return;
            }

            $service = $this->app->make(WooCommerceService::class);

            // Held back from the website (no photo yet) reaches the store the
            // same way a deactivated product does: whatever is listed there is
            // taken down, so turning the switch off pulls it, not just stops
            // future pushes.
            if ($product->publishesOnline()) {
                $service->syncProduct($product);
            } else {
                $service->unpublishProduct($product);
            }
        });

        PosProduct::deleted(function (PosProduct $product): void {
            if ($this->storeReady()) {
                $this->app->make(WooCommerceService::class)->unpublishProduct($product);
            }
        });

        Event::listen(PosOrderPaid::class, function (PosOrderPaid $event): void {
            // Never let the online store break a completed sale. Every other
            // checkout listener swallows its own errors for this reason; this
            // one didn't, so a database missing its WooCommerce tables showed
            // the cashier an error screen AFTER the money was taken, and no
            // receipt went out.
            try {
                if (! $this->storeReady()) {
                    return;
                }

                $service = $this->app->make(WooCommerceService::class);

                foreach ($event->order->lines as $line) {
                    $product = PosProduct::query()->find($line->pos_product_id);
                    if ($product !== null && $product->publishesOnline()) {
                        $service->syncProduct($product);
                    }
                }
            } catch (\Throwable $e) {
                Log::error('WooCommerce stock push skipped after a sale', [
                    'order' => $event->order->reference,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    /**
     * Defensive: the store config table must exist (module installed) AND be
     * configured + enabled before any push. The table check guards the rare
     * case of a stray model hook on a database without the module.
     */
    private function storeReady(): bool
    {
        return Schema::hasTable('woocommerce_configuration')
            && WooCommerceConfiguration::current()->isConfigured();
    }
}
