<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Erp\Pricing\PricingPayload;
use App\Erp\Pricing\PricingVersion;
use App\Erp\Tenancy\WorkspaceManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Schema;
use Modules\Limousine\Models\LimoPortalConfiguration;
use Modules\Limousine\Support\PortalSignature;
use Throwable;

/**
 * The website's read of the published fares. Server-to-server, signed, and the
 * only way a price leaves this ERP.
 *
 * Signed with {@see PortalSignature::verifyRequest()} — the path-bound variant
 * — because this is a GET with no body: the plain scheme would sign only a
 * timestamp, leaving the workspace id in the URL unauthenticated and letting
 * one captured signature read every database.
 *
 * Sits outside session auth (WordPress has no session here), so it must expose
 * nothing but pricing and must not let any query parameter change its answer.
 */
final class PricingApiController
{
    public function __invoke(Request $request, WorkspaceManager $workspaces, string $ws): Response|JsonResponse
    {
        if (! ctype_digit($ws)) {
            return $this->unauthorized();
        }

        // The workspace has to be resolved BEFORE runFor(), which deliberately
        // falls through to the current database for an id it cannot find. That
        // is right for a background job and quite wrong here: it would answer
        // a request for workspace 999 with whichever business this connection
        // happens to be, over a signature that never named it. `find()` and
        // not `findAny()`, so a deleted database stops serving too.
        if (Schema::hasTable('workspaces') && $workspaces->find((int) $ws) === null) {
            return $this->unauthorized();
        }

        try {
            /** @var Response|JsonResponse $response */
            $response = $workspaces->runFor((int) $ws, function () use ($request, $ws): Response|JsonResponse {
                return $this->respond($request, $ws);
            });

            return $response;
        } catch (Throwable) {
            return $this->unauthorized();
        }
    }

    private function respond(Request $request, string $ws): Response|JsonResponse
    {
        // The shared secret lives on the Limousine portal config row. A
        // database without that module has no secret, so it can authenticate
        // nobody — say "unauthorized", not "server error".
        if (! Schema::hasTable('limo_portal_configuration')) {
            return $this->unauthorized();
        }

        $secret = (string) LimoPortalConfiguration::current()->shared_secret;

        // Deliberately NOT gated on the portal's `enabled` switch: turning the
        // payment portal off must not take the website's prices down with it.
        if ($secret === '') {
            return $this->unauthorized();
        }

        $verified = PortalSignature::verifyRequest(
            $request->getMethod(),
            '/' . ltrim($request->getPathInfo(), '/'),
            $request->getContent(),
            (string) $request->header(PortalSignature::TIMESTAMP_HEADER, ''),
            (string) $request->header(PortalSignature::SIGNATURE_HEADER, ''),
            $secret,
        );

        if (! $verified) {
            return $this->unauthorized();
        }

        // A workspace whose pricing tables were never migrated answers cleanly
        // rather than 500ing halfway through a query.
        if (! Schema::hasTable('pricing_services')) {
            return response()->json(['error' => 'pricing unavailable'], 503);
        }

        $version = PricingVersion::current();
        $etag = '"' . $version . '"';

        // The common case by far: nothing changed since the site last asked.
        if (trim((string) $request->header('If-None-Match', '')) === $etag) {
            return response('', 304)->header('ETag', $etag);
        }

        return response()
            ->json(app(PricingPayload::class)->build(), 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ->header('ETag', $etag)
            ->header('Cache-Control', 'no-cache, private');
    }

    /** One shape for every rejection — never a hint about which part failed. */
    private function unauthorized(): JsonResponse
    {
        return response()->json(['error' => 'unauthorized'], 401);
    }
}
