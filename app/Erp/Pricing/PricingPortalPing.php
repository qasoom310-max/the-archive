<?php

declare(strict_types=1);

namespace App\Erp\Pricing;

use App\Erp\Tenancy\WorkspaceManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Limousine\Models\LimoPortalConfiguration;
use Modules\Limousine\Support\PortalSignature;
use Throwable;

/**
 * Tells the website a new version exists. It carries NO prices — the site
 * comes and fetches them itself, so data flows one way and a forged ping can
 * do nothing worse than make WordPress ask a question.
 *
 * Synchronous on purpose. The queue here is drained by a once-a-minute cron,
 * so queuing would mean the site sits on stale fares for up to a minute, and a
 * queued job runs in Main's context unless it carries the workspace id — a
 * trap this codebase has been bitten by before. A 3-second timeout with every
 * exception caught is both faster and safer.
 *
 * It must NEVER break a save: if WordPress is down, the price still saves.
 */
final class PricingPortalPing
{
    /** Seconds to wait on the website before giving up. */
    private const TIMEOUT = 3;

    /** A burst of saves sends one ping, not ten. */
    private const DEBOUNCE_SECONDS = 5;

    public function __construct(private readonly WorkspaceManager $workspaces) {}

    /**
     * Fire the ping. Returns a short human result for the admin screen's
     * manual button; never throws.
     *
     * @return array{sent: bool, status: int|null, message: string}
     */
    public function send(int $version, bool $force = false): array
    {
        $config = LimoPortalConfiguration::current();

        $url = rtrim((string) $config->portal_url, '/');
        $secret = (string) $config->shared_secret;

        if ($url === '' || $secret === '') {
            return ['sent' => false, 'status' => null, 'message' => 'Website URL or shared secret is not set.'];
        }

        // The manual button skips the debounce — it exists precisely for when
        // the two sides look out of sync and the owner wants to force it.
        if (! $force && ! $this->claimDebounceSlot()) {
            return ['sent' => false, 'status' => null, 'message' => 'Skipped — another update was just sent.'];
        }

        $endpoint = $url . '/wp-json/wanaan/v1/pricing/refresh';
        $path = (string) parse_url($endpoint, PHP_URL_PATH);

        $body = (string) json_encode(
            ['version' => $version, 'ws' => $this->workspaceId()],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        $timestamp = (string) time();

        try {
            $response = Http::connectTimeout(self::TIMEOUT)
                ->timeout(self::TIMEOUT)
                ->withHeaders([
                    PortalSignature::TIMESTAMP_HEADER => $timestamp,
                    PortalSignature::SIGNATURE_HEADER => PortalSignature::signRequest(
                        'POST',
                        $path,
                        $body,
                        $timestamp,
                        $secret,
                    ),
                    'Accept' => 'application/json',
                ])
                ->withBody($body, 'application/json')
                ->post($endpoint);
        } catch (Throwable $e) {
            // The website being unreachable is not this ERP's problem to solve:
            // it refreshes on its own timer anyway. Log and move on.
            Log::warning('Pricing refresh ping failed', ['error' => $e->getMessage()]);

            return ['sent' => false, 'status' => null, 'message' => 'The website could not be reached.'];
        }

        if (! $response->successful()) {
            Log::warning('Pricing refresh ping rejected', ['status' => $response->status()]);

            return [
                'sent' => false,
                'status' => $response->status(),
                'message' => 'The website answered ' . $response->status() . '.',
            ];
        }

        return ['sent' => true, 'status' => $response->status(), 'message' => 'The website was told about v' . $version . '.'];
    }

    /** True when this caller won the slot; false while a recent ping holds it. */
    private function claimDebounceSlot(): bool
    {
        return Cache::add('pricing.ping.lock', 1, self::DEBOUNCE_SECONDS);
    }

    /** Which database these fares belong to, so the site fetches the right ones. */
    private function workspaceId(): ?int
    {
        try {
            $workspace = $this->workspaces->current();

            return $workspace->is_main ? null : (int) $workspace->getKey();
        } catch (Throwable) {
            return null;
        }
    }
}
