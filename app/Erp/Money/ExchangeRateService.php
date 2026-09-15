<?php

declare(strict_types=1);

namespace App\Erp\Money;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Live exchange rates for converting a foreign-currency quote into BHD.
 *
 * Backed by a free, keyless public API (open.er-api.com, daily-refreshed
 * mid-market rates) — nothing to configure, no account, no admin-maintained
 * rate table. A successful lookup is cached for CACHE_HOURS so picking the
 * same currency again (on this leg, another leg, or another booking) doesn't
 * re-hit the API; a FAILED lookup is never cached, so a retry genuinely
 * tries again rather than replaying the same miss for hours.
 *
 * Never throws — a network hiccup here must not break a form the office is
 * halfway through filling in; the caller gets a null and can show a retry.
 */
final class ExchangeRateService
{
    private const BASE_URL = 'https://open.er-api.com/v6/latest/';
    private const CACHE_HOURS = 6;

    public function __construct(private readonly HttpFactory $http)
    {
    }

    /** How many :to units one unit of :from is worth, or null if it could not be fetched. */
    public function rate(string $from, string $to): ?float
    {
        $from = strtoupper($from);
        $to = strtoupper($to);

        if ($from === $to) {
            return 1.0;
        }

        $cacheKey = "exchange_rate:{$from}:{$to}";
        $cached = Cache::get($cacheKey);

        if (is_float($cached)) {
            return $cached;
        }

        $value = $this->fetch($from, $to);

        if ($value !== null) {
            Cache::put($cacheKey, $value, now()->addHours(self::CACHE_HOURS));
        }

        return $value;
    }

    private function fetch(string $from, string $to): ?float
    {
        try {
            $response = $this->http->timeout(5)->acceptJson()->get(self::BASE_URL . $from);
        } catch (\Throwable $e) {
            Log::warning('Exchange rate lookup for ' . $from . ' failed: ' . $e->getMessage());

            return null;
        }

        if (! $response->successful()) {
            Log::warning('Exchange rate lookup for ' . $from . ' returned HTTP ' . $response->status() . '.');

            return null;
        }

        $value = $response->json('rates.' . $to);

        if (! is_numeric($value)) {
            Log::warning('Exchange rate lookup for ' . $from . '->' . $to . ' had no usable rate in the response.');

            return null;
        }

        return (float) $value;
    }
}
