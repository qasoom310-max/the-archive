<?php

declare(strict_types=1);

namespace Modules\WooCommerce\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Pos\Models\PosProduct;
use Modules\WooCommerce\Exceptions\WooCommerceException;
use Modules\WooCommerce\Models\WooCommerceConfiguration;
use Modules\WooCommerce\Models\WooCommerceProductLink;

/**
 * Performs the WooCommerce REST call off the request thread so the UI never
 * blocks on the store. Resolves the (latest) credentials + product state at
 * run time, then creates / updates / drafts the remote product. A non-2xx
 * response throws so the job retries, then lands in `failed_jobs`.
 *
 * Passing only the product id (not a built payload) means a sync queued per
 * keystroke during an auto-save always sends the FINAL state when it runs.
 */
final class SyncProductToWooCommerce implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /**
     * @param string $action 'sync' (create-or-update) or 'unpublish' (→ draft)
     */
    public function __construct(
        public readonly int $posProductId,
        public readonly string $action = 'sync',
    ) {
    }

    public function handle(HttpFactory $http): void
    {
        $config = WooCommerceConfiguration::current();

        // Disabled / unconfigured at run time → drop quietly (don't retry).
        if (! $config->isConfigured()) {
            return;
        }

        $link = WooCommerceProductLink::forProduct($this->posProductId);

        if ($this->action === 'unpublish') {
            $this->unpublish($http, $config, $link);

            return;
        }

        $product = PosProduct::query()->find($this->posProductId);
        if ($product === null) {
            return;
        }

        $payload = $this->buildPayload($product);
        $client = $this->client($http, $config);

        $response = $link->woo_id !== null
            ? $client->put($config->apiBase() . '/products/' . $link->woo_id, $payload)
            : $client->post($config->apiBase() . '/products', $payload);

        if (! $response->successful()) {
            $this->fail($link, $response->status(), $response->body());
        }

        $remoteId = $response->json('id');
        $link->woo_id = is_int($remoteId) ? $remoteId : $link->woo_id;
        $link->last_status = 'synced';
        $link->last_error = null;
        $link->last_synced_at = now();
        $link->save();
    }

    private function unpublish(HttpFactory $http, WooCommerceConfiguration $config, WooCommerceProductLink $link): void
    {
        if ($link->woo_id === null) {
            return; // never pushed — nothing on the store to hide
        }

        $response = $this->client($http, $config)
            ->put($config->apiBase() . '/products/' . $link->woo_id, ['status' => 'draft']);

        if (! $response->successful()) {
            $this->fail($link, $response->status(), $response->body());
        }

        $link->last_status = 'unpublished';
        $link->last_error = null;
        $link->last_synced_at = now();
        $link->save();
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
     * as HTTP Basic auth over HTTPS.
     */
    private function client(HttpFactory $http, WooCommerceConfiguration $config): PendingRequest
    {
        return $http->acceptJson()
            ->withBasicAuth((string) $config->consumer_key, (string) $config->consumer_secret);
    }

    private function fail(WooCommerceProductLink $link, int $status, string $body): never
    {
        $message = 'WooCommerce API error (HTTP ' . $status . '): ' . Str::limit($body, 500);

        $link->last_status = 'failed';
        $link->last_error = $message;
        $link->save();

        throw new WooCommerceException($message);
    }

    /** Seconds between retries. */
    public function backoff(): int
    {
        return 30;
    }
}
