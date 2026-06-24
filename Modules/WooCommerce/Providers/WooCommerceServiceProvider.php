<?php

declare(strict_types=1);

namespace Modules\WooCommerce\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Modules\Pos\Events\PosOrderPaid;
use Modules\Pos\Models\PosProduct;
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
        'name', 'price', 'barcode', 'stock_on_hand', 'image_path', 'pos_category_id', 'active',
    ];

    public function register(): void
    {
        $this->app->singleton(WooCommerceService::class);
    }

    public function boot(): void
    {
        PosProduct::saved(function (PosProduct $product): void {
            if (! $this->storeReady()) {
                return;
            }

            // Auto-save fires per keystroke — only react to a meaningful change.
            if (! $product->wasRecentlyCreated && ! $product->wasChanged(self::SYNCED_FIELDS)) {
                return;
            }

            $service = $this->app->make(WooCommerceService::class);

            if ($product->active) {
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
            if (! $this->storeReady()) {
                return;
            }

            $service = $this->app->make(WooCommerceService::class);

            foreach ($event->order->lines as $line) {
                $product = PosProduct::query()->find($line->pos_product_id);
                if ($product !== null && $product->active) {
                    $service->syncProduct($product);
                }
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
