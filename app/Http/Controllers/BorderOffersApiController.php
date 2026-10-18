<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Erp\Tenancy\WorkspaceManager;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Modules\Limousine\Models\LimoPortalConfiguration;
use Modules\Limousine\Support\BorderOffers;
use Modules\Limousine\Support\PortalSignature;
use Throwable;

/**
 * The website's read of the cross-border empty-car offer: for a direction and
 * a day, which departure hours are discounted. Server-to-server and signed
 * like the fares read ({@see PricingApiController}), with one difference: the
 * signature covers the query string as well as the path, because here the
 * query (direction and day) IS the question.
 *
 * Answers only hours and counts — never a booking, a customer or a car.
 */
final class BorderOffersApiController
{
    public function __invoke(Request $request, WorkspaceManager $workspaces, string $ws): JsonResponse
    {
        if (! ctype_digit($ws)) {
            return $this->unauthorized();
        }

        // Resolved before runFor(), which falls through to the current
        // database for an unknown id — see PricingApiController.
        if (Schema::hasTable('workspaces') && $workspaces->find((int) $ws) === null) {
            return $this->unauthorized();
        }

        try {
            /** @var JsonResponse $response */
            $response = $workspaces->runFor((int) $ws, fn (): JsonResponse => $this->respond($request));

            return $response;
        } catch (Throwable $e) {
            report($e);

            return $this->unauthorized();
        }
    }

    private function respond(Request $request): JsonResponse
    {
        if (! Schema::hasTable('limo_portal_configuration')) {
            return $this->unauthorized();
        }

        $secret = (string) LimoPortalConfiguration::current()->shared_secret;
        if ($secret === '') {
            return $this->unauthorized();
        }

        $query = (string) $request->server('QUERY_STRING', '');
        $verified = PortalSignature::verifyRequest(
            $request->getMethod(),
            '/' . ltrim($request->getPathInfo(), '/') . ($query !== '' ? '?' . $query : ''),
            $request->getContent(),
            (string) $request->header(PortalSignature::TIMESTAMP_HEADER, ''),
            (string) $request->header(PortalSignature::SIGNATURE_HEADER, ''),
            $secret,
        );

        if (! $verified) {
            return $this->unauthorized();
        }

        if (! Schema::hasTable('limo_legs') || ! Schema::hasTable('pricing_settings')) {
            return response()->json(['error' => 'unavailable'], 503);
        }

        $from = strtoupper((string) $request->query('from', ''));
        $to = strtoupper((string) $request->query('to', ''));
        $date = (string) $request->query('date', '');

        if (! in_array($from, BorderOffers::COUNTRIES, true) || ! in_array($to, BorderOffers::COUNTRIES, true)) {
            return response()->json(['error' => 'unknown country'], 422);
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return response()->json(['error' => 'bad date'], 422);
        }

        // The customer's time on the road, whole hours; absent = the set drive time.
        $hours = $request->query('hours');
        $tripHours = null;
        if ($hours !== null) {
            if (! is_string($hours) || ! ctype_digit($hours)
                || (int) $hours < BorderOffers::MIN_TRIP_HOURS || (int) $hours > BorderOffers::MAX_TRIP_HOURS) {
                return response()->json(['error' => 'bad hours'], 422);
            }
            $tripHours = (int) $hours;
        }

        try {
            $day = CarbonImmutable::createFromFormat('!Y-m-d', $date);
        } catch (Throwable) {
            $day = null;
        }

        $offers = BorderOffers::make();
        $today = now()->startOfDay();

        if (! $day instanceof CarbonImmutable || $day->format('Y-m-d') !== $date
            || $day->lt($today) || $day->gt($today->copy()->addDays(BorderOffers::MAX_DAYS_AHEAD))) {
            return response()->json(['error' => 'bad date'], 422);
        }

        return response()
            ->json($offers->payload($from, $to, $day, $tripHours), 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ->header('Cache-Control', 'no-store, private');
    }

    /** One shape for every rejection — never a hint about which part failed. */
    private function unauthorized(): JsonResponse
    {
        return response()->json(['error' => 'unauthorized'], 401);
    }
}
