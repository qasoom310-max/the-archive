<?php

declare(strict_types=1);

namespace Modules\WooCommerce\Services;

use App\Erp\Tenancy\WorkspaceManager;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Pos\Models\PosProduct;
use Modules\WooCommerce\Jobs\SyncProductToWooCommerce;
use Modules\WooCommerce\Models\WooCommerceConfiguration;
use Modules\WooCommerce\Models\WooCommerceProductLink;

/**
 * Application-facing entry point for pushing POS products to WooCommerce.
 *
 * Two ways in:
 *  - {@see syncProduct()} / {@see unpublishProduct()} QUEUE a push (used by the
 *    product save / sale hooks so the UI never waits). The job re-activates the
 *    originating workspace before running — the queue is pinned to Main, so
 *    without that a tenant's job would run against Main's (wrong) database.
 *  - {@see syncAllActiveNow()} / {@see pushNow()} run the REST call SYNCHRONOUSLY
 *    in the CURRENT request's database context (the "Sync all now" button). No
 *    queue / cron, and errors are reported back immediately.
 *
 * Everything no-ops when the store isn't configured for the active database.
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

        $this->bus->dispatch(new SyncProductToWooCommerce((int) $product->id, 'sync', $this->currentWorkspaceId()));
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

        $this->bus->dispatch(new SyncProductToWooCommerce((int) $product->id, 'unpublish', $this->currentWorkspaceId()));
    }

    /**
     * Synchronously push every active product to the store, in the CURRENT
     * database context. Returns a tally for immediate UI feedback.
     *
     * @return array{synced: int, failed: int, error: ?string}
     */
    public function syncAllActiveNow(): array
    {
        if (! WooCommerceConfiguration::current()->isConfigured()) {
            return ['synced' => 0, 'failed' => 0, 'error' => null];
        }

        $synced = 0;
        $failed = 0;
        $error = null;

        foreach (PosProduct::query()->where('active', true)->get() as $product) {
            $result = $this->pushNow((int) $product->id, 'sync');

            if ($result['ok']) {
                $synced++;
            } elseif (! $result['skipped']) {
                $failed++;
                $error ??= $result['error'];
            }
        }

        return ['synced' => $synced, 'failed' => $failed, 'error' => $error];
    }

    /**
     * Perform ONE product push synchronously against the active database's
     * store. Never throws — returns a structured result so callers (the button
     * and the queued job) decide what to do.
     *
     * @return array{ok: bool, skipped: bool, error: ?string}
     */
    public function pushNow(int $posProductId, string $action = 'sync'): array
    {
        $config = WooCommerceConfiguration::current();
        if (! $config->isConfigured()) {
            return ['ok' => false, 'skipped' => true, 'error' => null];
        }

        $link = WooCommerceProductLink::forProduct($posProductId);

        if ($action === 'unpublish') {
            if ($link->woo_id === null) {
                return ['ok' => false, 'skipped' => true, 'error' => null];
            }

            $response = $this->client($config)
                ->put($config->apiBase() . '/products/' . $link->woo_id, ['status' => 'draft']);

            return $this->finish($link, $response, 'unpublished');
        }

        $product = PosProduct::query()->find($posProductId);
        if ($product === null) {
            return ['ok' => false, 'skipped' => true, 'error' => null];
        }

        $payload = $this->buildPayload($product);

        $response = $link->woo_id !== null
            ? $this->client($config)->put($config->apiBase() . '/products/' . $link->woo_id, $payload)
            : $this->client($config)->post($config->apiBase() . '/products', $payload);

        if ($response->successful()) {
            $remoteId = $response->json('id');
            if (is_int($remoteId)) {
                $link->woo_id = $remoteId;
            }
        }

        return $this->finish($link, $response, 'synced');
    }

    /**
     * Record the outcome on the link row and return a result.
     *
     * @return array{ok: bool, skipped: bool, error: ?string}
     */
    private function finish(WooCommerceProductLink $link, \Illuminate\Http\Client\Response $response, string $okStatus): array
    {
        if (! $response->successful()) {
            $error = 'HTTP ' . $response->status() . ': ' . Str::limit($response->body(), 300);
            $link->last_status = 'failed';
            $link->last_error = $error;
            $link->save();

            return ['ok' => false, 'skipped' => false, 'error' => $error];
        }

        $link->last_status = $okStatus;
        $link->last_error = null;
        $link->last_synced_at = now();
        $link->save();

        return ['ok' => true, 'skipped' => false, 'error' => null];
    }

    /**
     * Map a POS product onto a WooCommerce product payload.
     *
     * @return array<string, mixed>
     */
    private function buildPayload(PosProduct $product): array
    {
        // Prefer the English name; fall back to the active-locale value.
        $name = $product->getTranslation('name', 'en');
        if ($name === '') {
            $name = (string) $product->name;
        }

        $payload = [
            'name' => $name,
            'type' => 'simple',
            'status' => $product->active ? 'publish' : 'draft',
            'regular_price' => (string) $product->price,
            'manage_stock' => true,
            'stock_quantity' => (int) round((float) $product->stock_on_hand),
        ];

        $barcode = (string) $product->barcode;
        if ($barcode !== '') {
            $payload['sku'] = $barcode;
        }

        $image = (string) $product->image_path;
        if ($image !== '') {
            // Absolute URL so the store can fetch the file from this server.
            $payload['images'] = [
                ['src' => Storage::disk('public')->url($image)],
            ];
        }

        return $payload;
    }

    /**
     * Authenticated REST client. WooCommerce accepts the consumer key/secret
     * as HTTP Basic auth over HTTPS. A finite timeout keeps a slow/unreachable
     * store from hanging the request thread.
     */
    private function client(WooCommerceConfiguration $config): PendingRequest
    {
        return app(HttpFactory::class)->acceptJson()
            ->timeout(20)
            ->withBasicAuth((string) $config->consumer_key, (string) $config->consumer_secret);
    }

    /**
     * The workspace whose database is active right now, captured at DISPATCH
     * time so the queued job can re-activate it (the queue itself runs in Main's
     * context). Null when the workspaces feature isn't present.
     */
    private function currentWorkspaceId(): ?int
    {
        if (! Schema::hasTable('workspaces')) {
            return null;
        }

        return (int) app(WorkspaceManager::class)->current()->id;
    }
}
