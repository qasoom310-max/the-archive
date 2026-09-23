<?php

declare(strict_types=1);

namespace Modules\WooCommerce\Livewire;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\WooCommerce\Models\WooCommerceConfiguration;
use Modules\WooCommerce\Services\WooCommerceService;

/**
 * Admin-only editor for the WooCommerce store connection, surfaced as the
 * "WooCommerce" tab of Settings. The consumer key/secret are write-only —
 * stored values are never echoed back; a blank field on save means "keep the
 * existing value". A "Sync all now" button backfills the store with every
 * active product.
 */
#[Layout('components.layouts.app')]
#[Title('WooCommerce settings')]
final class WooCommerceSettings extends Component
{
    public string $storeUrl = '';

    public string $apiVersion = 'wc/v3';

    public bool $enabled = false;

    // Write-only: never pre-filled with the stored secret.
    public string $consumerKey = '';

    public string $consumerSecret = '';

    public bool $hasConsumerKey = false;

    public bool $hasConsumerSecret = false;

    public bool $saved = false;

    public string $syncMessage = '';

    public function mount(): void
    {
        $this->authorizeAdmin();

        $config = WooCommerceConfiguration::current();

        $this->storeUrl = (string) $config->store_url;
        $this->apiVersion = (string) $config->api_version !== '' ? (string) $config->api_version : 'wc/v3';
        $this->enabled = (bool) $config->enabled;
        $this->hasConsumerKey = (string) $config->consumer_key !== '';
        $this->hasConsumerSecret = (string) $config->consumer_secret !== '';
    }

    private function authorizeAdmin(): void
    {
        $user = Auth::user();

        abort_unless($user instanceof User && $user->isAdmin(), 403, 'Settings are administrator-only.');
    }

    public function save(): void
    {
        $this->authorizeAdmin();

        $this->validate([
            'storeUrl' => ['nullable', 'url', 'max:255'],
            'apiVersion' => ['required', 'string', 'max:20'],
        ]);

        $config = WooCommerceConfiguration::current();

        $config->store_url = trim($this->storeUrl) ?: null;
        $config->api_version = trim($this->apiVersion) ?: 'wc/v3';
        $config->enabled = $this->enabled;

        // Blank = keep the stored secret untouched.
        if (trim($this->consumerKey) !== '') {
            $config->consumer_key = trim($this->consumerKey);
        }

        if (trim($this->consumerSecret) !== '') {
            $config->consumer_secret = trim($this->consumerSecret);
        }

        if ($config->enabled && ($config->store_url === null || (string) $config->consumer_key === '' || (string) $config->consumer_secret === '')) {
            $this->addError('enabled', __('A store URL, consumer key and secret are required before enabling sync.'));

            return;
        }

        $config->save();

        $this->consumerKey = '';
        $this->consumerSecret = '';
        $this->hasConsumerKey = (string) $config->consumer_key !== '';
        $this->hasConsumerSecret = (string) $config->consumer_secret !== '';
        $this->saved = true;
    }

    public bool $syncError = false;

    /**
     * Push every active product to the store NOW (synchronously, in this
     * database's context) and report the real outcome — so a wrong key /
     * unreachable store surfaces immediately instead of failing silently in a
     * background job.
     */
    public function syncAllNow(): void
    {
        $this->authorizeAdmin();

        if (! WooCommerceConfiguration::current()->isConfigured()) {
            $this->syncError = true;
            $this->syncMessage = __('Configure and enable the store, then Save first.');

            return;
        }

        // Whatever the store does — refuse, hang up, answer in a shape nobody
        // expected — the admin should be told, not shown a 500 page with no
        // way of knowing how far the sync got.
        try {
            $result = app(WooCommerceService::class)->syncAllActiveNow();
        } catch (\Throwable $e) {
            report($e);

            $this->syncError = true;
            $this->syncMessage = __('The sync stopped: :error', ['error' => Str::limit($e->getMessage(), 200)]);

            return;
        }

        $this->syncError = $result['failed'] > 0;

        if ($result['synced'] === 0 && $result['failed'] === 0) {
            $this->syncMessage = __('No active products in THIS database to sync — switch to the right database (My database) or add products here first.');

            return;
        }

        if ($result['failed'] > 0) {
            $this->syncMessage = __(':synced synced, :failed failed. First error: :error', [
                'synced' => $result['synced'],
                'failed' => $result['failed'],
                'error' => (string) $result['error'],
            ]);

            return;
        }

        if ($result['remaining'] > 0) {
            // The run works to a time budget so it can't die half-way past the
            // web server's timeout. Whatever is left is picked up on the next press.
            $this->syncMessage = __(':count products synced. :remaining still to go — press Sync again to continue.', [
                'count' => $result['synced'],
                'remaining' => $result['remaining'],
            ]);

            return;
        }

        $this->syncMessage = __(':count products synced to the store.', ['count' => $result['synced']]);
    }

    public function updated(): void
    {
        $this->saved = false;
        $this->syncMessage = '';
        $this->syncError = false;
    }

    public function render(): View
    {
        return view('woocommerce::settings', [
            'configured' => WooCommerceConfiguration::current()->isConfigured(),
        ]);
    }
}
