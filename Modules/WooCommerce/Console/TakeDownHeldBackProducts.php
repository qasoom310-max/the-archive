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

                if ($product !== null && $product->publishesOnline()) {
                    continue;
                }

                // A product deleted in the ERP is the plainest case of all —
                // it is not sold here any more, so it must not still be on the
                // website. The delete hook pushes a takedown, but only the once:
                // if the store was down or was connected after the delete, the
                // listing stays up and nothing else would ever remove it. The
                // link row survives the delete precisely so this can find it.
                $reason = match (true) {
                    $product === null => 'deleted in the ERP',
                    $product->missingForOnlineStore() !== [] => 'missing '.implode(', ', $product->missingForOnlineStore()),
                    default => 'held back',
                };

                $name = $product !== null ? (string) $product->name : '#'.$link->pos_product_id;

                $this->line(sprintf('%s: %s — %s', $label, $name, $reason));

                if (! $pretend) {
                    // Unpublishing needs only the link's remote id, never the
                    // product, so a deleted one comes down the same way.
                    $service->pushNow((int) $link->pos_product_id, 'unpublish');
                }

                $taken++;
            }

            $this->info(sprintf('%s: %s %d listing(s).', $label, $pretend ? 'would take down' : 'took down', $taken));
        });

        return self::SUCCESS;
    }
}
