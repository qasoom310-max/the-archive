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
    /** Seconds the manual "sync all" may spend before handing the rest back. */
    private const SYNC_ALL_BUDGET_SECONDS = 20.0;

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
     * @return array{synced: int, failed: int, remaining: int, error: ?string}
     */
    public function syncAllActiveNow(): array
    {
        if (! WooCommerceConfiguration::current()->isConfigured()) {
            return ['synced' => 0, 'failed' => 0, 'remaining' => 0, 'error' => null];
        }

        $synced = 0;
        $failed = 0;
        $remaining = 0;
        $error = null;

        // The store can take seconds per product, so a big catalogue ran past
        // the web server's timeout and died mid-way with nothing recorded.
        // Work to a time budget instead and report what is left, so the admin
        // presses the button again and picks up where it stopped (products
        // already pushed are cheap updates).
        $deadline = microtime(true) + self::SYNC_ALL_BUDGET_SECONDS;

        foreach (PosProduct::query()->where('active', true)->cursor() as $product) {
            if (microtime(true) >= $deadline) {
                $remaining++;

                continue;
            }

            $result = $this->pushNow((int) $product->id, 'sync');

            if ($result['ok']) {
                $synced++;
            } elseif (! $result['skipped']) {
                $failed++;
                $error ??= $result['error'];
            }
        }

        return ['synced' => $synced, 'failed' => $failed, 'remaining' => $remaining, 'error' => $error];
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

        $payload = $this->buildPayload($product, $config);

        // A create whose reply never arrived (slow store, timeout) still made
        // the product ON the store — retrying would make it a second and third
        // time. Look for the existing listing first and adopt it, so a retry
        // updates rather than duplicates.
        if ($link->woo_id === null) {
            $existing = $this->findRemoteId($product, $config);

            if ($existing !== null) {
                $link->woo_id = $existing;
                $link->save();
            }
        }

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
     * Find this product's existing listing on the store, so a retried create
     * adopts it instead of duplicating. Matched on SKU (WooCommerce keeps those
     * unique) and, failing that, on an exact name. Best effort — any problem
     * returns null and the caller creates as before.
     */
    private function findRemoteId(PosProduct $product, WooCommerceConfiguration $config): ?int
    {
        try {
            $sku = trim((string) ($product->barcode ?? ''));

            if ($sku !== '') {
                $response = $this->client($config)->get($config->apiBase() . '/products', ['sku' => $sku]);
                $id = $response->successful() ? $response->json('0.id') : null;

                return is_int($id) ? $id : null;
            }

            $name = $product->getTranslation('name', 'en');
            if ($name === '') {
                $name = (string) $product->name;
            }
            if ($name === '') {
                return null;
            }

            $response = $this->client($config)->get($config->apiBase() . '/products', [
                'search' => $name,
                'per_page' => 20,
            ]);

            if (! $response->successful()) {
                return null;
            }

            /** @var array<int, array<string, mixed>> $rows */
            $rows = $response->json() ?? [];

            foreach ($rows as $row) {
                if (is_array($row)
                    && is_string($row['name'] ?? null)
                    && mb_strtolower($row['name']) === mb_strtolower($name)
                    && is_int($row['id'] ?? null)
                ) {
                    return $row['id'];
                }
            }

            return null;
        } catch (\Throwable) {
            return null;
        }
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
    private function buildPayload(PosProduct $product, WooCommerceConfiguration $config): array
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

        // Primary photo first, then every secondary (gallery) image — so the
        // store lists them in the same order, the primary as the featured one.
        // Absolute URLs so the store can fetch each file from this server.
        $images = [];
        $primary = (string) $product->image_path;
        if ($primary !== '') {
            $images[] = ['src' => Storage::disk('public')->url($primary)];
        }
        foreach ($product->galleryImages() as $path) {
            $images[] = ['src' => Storage::disk('public')->url($path)];
        }
        if ($images !== []) {
            $payload['images'] = $images;
        }

        // Category: resolve the product's POS category name to a WooCommerce
        // category term (matched by name, created on the store if missing) so
        // the listing isn't "Uncategorized". Best-effort — a category that
        // can't be resolved just leaves the product uncategorised; it never
        // fails the product push.
        $categoryName = $this->categoryName($product);
        if ($categoryName !== '') {
            $termId = $this->resolveCategoryId($config, $categoryName);
            if ($termId !== null) {
                $payload['categories'] = [['id' => $termId]];
            }
        }

        return $payload;
    }

    /** The product's POS category name in English (matches the WC category). */
    private function categoryName(PosProduct $product): string
    {
        $category = $product->category;
        if ($category === null) {
            return '';
        }

        $name = $category->getTranslation('name', 'en');

        return $name !== '' ? $name : (string) $category->name;
    }

    /**
     * Resolve a WooCommerce product-category term id by name: find an existing
     * one (exact, case-insensitive) else create it on the store. Best-effort —
     * returns null on any failure so a category hiccup never blocks the product
     * push. Cached per name for the life of this (singleton) service so a
     * "Sync all" doesn't re-resolve the same category for every product.
     *
     * @var array<string, int>
     */
    private array $categoryIdCache = [];

    private function resolveCategoryId(WooCommerceConfiguration $config, string $name): ?int
    {
        $key = mb_strtolower($name);
        if (isset($this->categoryIdCache[$key])) {
            return $this->categoryIdCache[$key];
        }

        $base = $config->apiBase() . '/products/categories';

        // 1) Search existing categories (WooCommerce search is a partial match,
        //    so filter for an exact, case-insensitive name match).
        $search = $this->client($config)->get($base, ['search' => $name, 'per_page' => 100]);
        if ($search->successful()) {
            foreach ((array) $search->json() as $term) {
                if (is_array($term) && isset($term['id'], $term['name'])
                    && mb_strtolower((string) $term['name']) === $key) {
                    return $this->categoryIdCache[$key] = (int) $term['id'];
                }
            }
        }

        // 2) Not found → create it.
        $create = $this->client($config)->post($base, ['name' => $name]);
        if ($create->successful()) {
            $id = $create->json('id');
            if (is_int($id)) {
                return $this->categoryIdCache[$key] = $id;
            }
        }

        // A 400 "term_exists" race carries the existing id in data.resource_id.
        $existingId = $create->json('data.resource_id');
        if (is_int($existingId)) {
            return $this->categoryIdCache[$key] = $existingId;
        }

        return null;
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
