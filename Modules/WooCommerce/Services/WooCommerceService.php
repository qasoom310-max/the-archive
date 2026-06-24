<?php

declare(strict_types=1);

namespace Modules\WooCommerce\Services;

use Illuminate\Contracts\Bus\Dispatcher;
use Modules\Pos\Models\PosProduct;
use Modules\WooCommerce\Jobs\SyncProductToWooCommerce;
use Modules\WooCommerce\Models\WooCommerceConfiguration;
use Modules\WooCommerce\Models\WooCommerceProductLink;

/**
 * Application-facing entry point for pushing POS products to WooCommerce.
 *
 * Queueing is cheap and synchronous; the blocking REST round-trip happens
 * inside the queued {@see SyncProductToWooCommerce} job, so the terminal /
 * product form never waits on the store. Every method is a silent no-op when
 * the store isn't configured, so callers can fire unconditionally.
 */
final class WooCommerceService
{
    public function __construct(private readonly Dispatcher $bus)
    {
    }

    /** Queue an upsert (create-or-update) of a product on the store. */
    public function syncProduct(PosProduct $product): void
    {
        if (! WooCommerceConfiguration::current()->isConfigured()) {
            return;
        }

        $link = WooCommerceProductLink::forProduct((int) $product->id);
        $link->last_status = 'queued';
        $link->save();

        $this->bus->dispatch(new SyncProductToWooCommerce((int) $product->id, 'sync'));
    }

    /**
     * Queue an unpublish (set the remote listing to draft). No-op if the
     * product was never pushed — there is nothing on the store to hide.
     */
    public function unpublishProduct(PosProduct $product): void
    {
        if (! WooCommerceConfiguration::current()->isConfigured()) {
            return;
        }

        $link = WooCommerceProductLink::query()
            ->where('pos_product_id', (int) $product->id)
            ->first();

        if ($link === null || $link->woo_id === null) {
            return;
        }

        $link->last_status = 'queued';
        $link->save();

        $this->bus->dispatch(new SyncProductToWooCommerce((int) $product->id, 'unpublish'));
    }

    /**
     * Queue a sync for every active product (the "Sync all now" button).
     * Returns the number of products queued.
     */
    public function syncAllActive(): int
    {
        if (! WooCommerceConfiguration::current()->isConfigured()) {
            return 0;
        }

        $count = 0;

        PosProduct::query()->where('active', true)->each(function (PosProduct $product) use (&$count): void {
            $this->syncProduct($product);
            $count++;
        });

        return $count;
    }
}
