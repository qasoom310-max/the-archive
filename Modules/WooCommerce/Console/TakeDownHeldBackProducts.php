<?php

declare(strict_types=1);

namespace Modules\WooCommerce\Console;

use App\Erp\Tenancy\EachDatabase;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Modules\Pos\Models\PosProduct;
use Modules\WooCommerce\Models\WooCommerceConfiguration;
use Modules\WooCommerce\Models\WooCommerceProductLink;
use Modules\WooCommerce\Services\WooCommerceService;

/**
 * `php artisan woocommerce:take-down-held-back`
 *
 * Take off the store every product this ERP no longer publishes — one that was
 * switched off by hand, deactivated, or is still missing the name, category,
 * price or photo a listing needs.
 *
 * Turning the switch off through the app already pushes the takedown, but a
 * product held back any other way (a data migration, an import, an edit made
 * before the store was connected) leaves a listing on the website that nobody
 * would think to go and remove. Running this on deploy makes the store agree
 * with the ERP without anyone having to press a button.
 *
 * A listing already taken down is left alone (its link reads `unpublished`),
 * so repeated runs cost nothing.
 */
final class TakeDownHeldBackProducts extends Command
{
    protected $signature = 'woocommerce:take-down-held-back
        {--pretend : Name what would come down, change nothing}';

    protected $description = 'Take products the ERP no longer publishes off the WooCommerce store.';

    public function handle(): int
    {
        EachDatabase::run(function (string $label): void {
            if (! Schema::hasTable('woocommerce_product_links') || ! Schema::hasTable('pos_products')) {
                return;
            }
            if (! WooCommerceConfiguration::current()->isConfigured()) {
                return;
            }

            $service = app(WooCommerceService::class);
            $pretend = (bool) $this->option('pretend');
            $taken = 0;

            // Only products the store actually holds a listing for.
            $links = WooCommerceProductLink::query()
                ->whereNotNull('woo_id')
                ->where(fn ($q) => $q->whereNull('last_status')->orWhere('last_status', '!=', 'unpublished'))
                ->get();

            foreach ($links as $link) {
                $product = PosProduct::query()->find($link->pos_product_id);
                if ($product === null || $product->publishesOnline()) {
                    continue;
                }

                $this->line(sprintf('%s: %s — %s', $label, (string) $product->name, $product->missingForOnlineStore() === []
                    ? 'held back'
                    : 'missing '.implode(', ', $product->missingForOnlineStore())));

                if (! $pretend) {
                    $service->pushNow((int) $product->id, 'unpublish');
                }

                $taken++;
            }

            $this->info(sprintf('%s: %s %d listing(s).', $label, $pretend ? 'would take down' : 'took down', $taken));
        });

        return self::SUCCESS;
    }
}
