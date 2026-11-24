<?php

declare(strict_types=1);

namespace Modules\WooCommerce\Livewire;

use App\Erp\Activity\ActivityLogger;
use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\Pos\Models\PosProduct;
use Modules\WooCommerce\Models\WooCommerceConfiguration;
use Modules\WooCommerce\Models\WooCommerceProductLink;
use Modules\WooCommerce\Services\WooCommerceService;

/**
 * "Website" box on the product page: whether this product reached the online
 * store, and if not, why, in the store's own words.
 *
 * Before this the answer lived only in `woocommerce_product_links`, which no
 * screen showed, so a product that never appeared on the website looked the
 * same as one that was fine. "Send to website now" pushes this one product
 * straight away in this request (no queue, no cron) and shows the result, so a
 * stuck background sender or a refusal from the store is visible at once.
 */
final class ProductWebsiteStatus extends Component
{
    /** A queued push older than this is treated as stuck. */
    private const STUCK_MINUTES = 10;

    #[Locked]
    public int $productId;

    /** Result of the last "Send now", shown under the status. */
    public string $result = '';

    public bool $resultOk = false;

    /**
     * Whether the box belongs on this database's product page at all: the
     * module's tables exist and a store address has been entered. A shop with
     * no website never sees it.
     */
    public static function available(): bool
    {
        try {
            return Schema::hasTable('woocommerce_configuration')
                && Schema::hasTable('woocommerce_product_links')
                && trim((string) WooCommerceConfiguration::current()->store_url) !== '';
        } catch (\Throwable) {
            return false;
        }
    }

    public function mount(int $productId): void
    {
        $this->guard(Permission::Read);
        $this->productId = $productId;
    }

    public function sendNow(): void
    {
        $this->guard(Permission::Write);

        $product = PosProduct::query()->find($this->productId);
        if ($product === null || ! WooCommerceConfiguration::current()->isConfigured()) {
            return;
        }

        $link = WooCommerceProductLink::query()->where('pos_product_id', $this->productId)->first();

        // Nothing to send and nothing on the store to take down.
        if (! $product->publishesOnline() && ($link === null || $link->woo_id === null)) {
            $this->resultOk = false;
            $this->result = __('Not sent: this product is not ready for the website (see above).');

            return;
        }

        $outcome = app(WooCommerceService::class)->pushNow($this->productId, 'sync');

        $this->resultOk = $outcome['ok'];
        $this->result = $outcome['ok']
            ? ($product->publishesOnline() ? __('Sent. The website has it now.') : __('Taken off the website.'))
            : __('The website refused it: :error', ['error' => (string) ($outcome['error'] ?? __('no reason given'))]);

        app(ActivityLogger::class)->log(
            'updated',
            (string) $product->name,
            $outcome['ok'] ? 'Sent to the website by hand' : 'Sending to the website failed: ' . (string) ($outcome['error'] ?? ''),
        );
    }

    /**
     * The product form saved: re-read the status (a ticked box, a new photo
     * or a sale price changes what the box should say). A queued push may
     * have started too, so the last "Send now" result is no longer current.
     */
    #[On('record-saved')]
    public function refreshAfterSave(): void
    {
        $this->result = '';
    }

    private function guard(Permission $permission): void
    {
        app(AccessControl::class)->authorize(Auth::user(), 'pos.product', $permission);
    }

    public function render(): View
    {
        $config = WooCommerceConfiguration::current();
        $product = PosProduct::query()->find($this->productId);
        $link = WooCommerceProductLink::query()->where('pos_product_id', $this->productId)->first();

        return view('woocommerce::product-website-status', [
            'state' => $this->state($config, $product, $link),
            'link' => $link,
            'storeLink' => $link?->woo_id !== null && $link->last_status !== 'unpublished'
                ? rtrim((string) $config->store_url, '/') . '/?p=' . $link->woo_id
                : null,
            'canSend' => $config->isConfigured()
                && $product !== null
                && ($product->publishesOnline() || $link?->woo_id !== null)
                && app(AccessControl::class)->allows(Auth::user(), 'pos.product', Permission::Write),
            'isAdmin' => (bool) Auth::user()?->isAdmin(),
        ]);
    }

    /**
     * One word for what the box says, in the order that matters: a connection
     * that is off explains everything below it, then a product not ready, then
     * what happened to the last push.
     */
    private function state(WooCommerceConfiguration $config, ?PosProduct $product, ?WooCommerceProductLink $link): string
    {
        if (! $config->isConfigured()) {
            return 'off';
        }
        if ($product === null) {
            return 'missing';
        }
        if (! $product->publishesOnline()) {
            return $link?->woo_id !== null && $link->last_status !== 'unpublished' ? 'take_down' : 'held_back';
        }

        return match ($link?->last_status) {
            'synced' => 'on',
            'failed' => 'failed',
            'unpublished' => 'taken_down',
            'queued' => $link->updated_at !== null && $link->updated_at->lt(now()->subMinutes(self::STUCK_MINUTES))
                ? 'stuck'
                : 'queued',
            default => 'never',
        };
    }
}
