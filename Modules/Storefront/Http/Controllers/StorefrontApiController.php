<?php

declare(strict_types=1);

namespace Modules\Storefront\Http\Controllers;

use App\Erp\Tenancy\WorkspaceManager;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Storefront\Models\StorefrontConfiguration;
use Modules\Storefront\Models\StorefrontOrder;
use Modules\Storefront\Services\StorefrontOrderIntake;
use Modules\Storefront\Support\Catalog;
use Modules\Storefront\Support\StoreSignature;

/**
 * The shop website's door: /storefront/v1/{ws}/…
 *
 * Server to server, no session. The workspace is in the path (and so inside
 * the signature); we enter that database, read ITS secret, and only then
 * verify. A database whose store connection is off answers 403 before any
 * signature is checked, so it cannot be probed.
 *
 *  GET  catalog              products, categories, shop details (ETag aware)
 *  POST orders               file a website order in the inbox
 *  GET  orders/{reference}   where that order stands (new/accepted/rejected)
 */
final class StorefrontApiController
{
    public function catalog(Request $request, int $ws, Catalog $catalog): Response|JsonResponse
    {
        return $this->guarded($request, $ws, function () use ($request, $catalog): Response|JsonResponse {
            $body = $catalog->build();
            $etag = '"' . sha1((string) json_encode($body)) . '"';

            if ($request->header('If-None-Match') === $etag) {
                return response('', 304)->header('ETag', $etag);
            }

            return response()->json($body)->header('ETag', $etag)->header('Cache-Control', 'no-store');
        });
    }

    public function placeOrder(Request $request, int $ws, StorefrontOrderIntake $intake): Response|JsonResponse
    {
        return $this->guarded($request, $ws, function () use ($request, $intake): JsonResponse {
            $data = json_decode($request->getContent(), true);
            if (! is_array($data)) {
                return response()->json(['error' => 'The body must be a JSON object.'], 400);
            }

            $result = $intake->receive($data);
            if (! $result['ok']) {
                return response()->json(['error' => 'The order was not accepted.', 'errors' => $result['errors']], 422);
            }

            return response()->json($this->orderBody($result['order']), $result['duplicate'] ? 200 : 201);
        });
    }

    public function orderStatus(Request $request, int $ws, string $reference): Response|JsonResponse
    {
        return $this->guarded($request, $ws, function () use ($reference): JsonResponse {
            $order = StorefrontOrder::query()->where('source_reference', $reference)->first();

            return $order === null
                ? response()->json(['error' => 'No order with that reference.'], 404)
                : response()->json($this->orderBody($order));
        });
    }

    /**
     * Enter the workspace, check the store is switched on, verify the
     * signature, then run the endpoint.
     *
     * @param  Closure(): (Response|JsonResponse)  $endpoint
     */
    private function guarded(Request $request, int $ws, Closure $endpoint): Response|JsonResponse
    {
        $manager = app(WorkspaceManager::class);

        // 0 is Main. Any other number must be a real business database: never
        // fall back to Main's data for a workspace that does not exist.
        if ($ws !== 0) {
            $workspace = $manager->findAny($ws);
            $path = $workspace?->databasePath();
            if ($workspace === null || $workspace->is_main || $path === null || ! is_file($path)) {
                return response()->json(['error' => 'Unknown store.'], 404);
            }
        }

        return $manager->runFor($ws === 0 ? null : $ws, function () use ($request, $endpoint): Response|JsonResponse {
            $config = StorefrontConfiguration::current();
            if (! $config->isConfigured()) {
                return response()->json(['error' => 'The online store is switched off.'], 403);
            }

            $verified = StoreSignature::verify(
                $request->method(),
                '/' . ltrim($request->path(), '/'),
                $request->getContent(),
                (string) $request->header(StoreSignature::TIMESTAMP_HEADER, ''),
                (string) $request->header(StoreSignature::SIGNATURE_HEADER, ''),
                (string) $config->shared_secret,
            );

            if (! $verified) {
                return response()->json(['error' => 'Invalid signature.'], 401);
            }

            return $endpoint();
        });
    }

    /** @return array<string, mixed> */
    private function orderBody(StorefrontOrder $order): array
    {
        return [
            'reference' => $order->source_reference,
            'status' => $order->status,
            'lines' => array_map(static fn (array $l): array => [
                'product_id' => $l['product_id'],
                'name' => $l['name'],
                'qty' => $l['qty'],
                'total' => $l['total'],
            ], $order->lines),
            'subtotal' => $order->subtotal,
            'delivery_charge' => $order->delivery_charge,
            'total' => $order->total,
            'created_at' => $order->created_at?->toIso8601String(),
        ];
    }
}
