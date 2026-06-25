<?php

declare(strict_types=1);

namespace App\Erp\Stream;

use App\Models\CloudflareStreamConfiguration;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Str;

/**
 * Thin client for the Cloudflare Stream API.
 *
 * The browser uploads the (large) video file DIRECTLY to Cloudflare via a
 * one-time "direct creator upload" URL this service mints — the ERP server
 * never handles the file. After upload we fetch the video's public watch URL
 * to share. Auth is a Bearer API token (Stream:Edit), per active database.
 */
final class CloudflareStreamService
{
    public function __construct(private readonly HttpFactory $http)
    {
    }

    /**
     * Mint a one-time direct-upload URL the browser POSTs the file to.
     *
     * @return array{uid: string, uploadURL: string}
     */
    public function createDirectUpload(string $name, int $maxDurationSeconds = 3600): array
    {
        $config = $this->configured();

        $response = $this->http
            ->withToken((string) $config->api_token)
            ->acceptJson()
            ->post($config->apiBase() . '/direct_upload', [
                'maxDurationSeconds' => $maxDurationSeconds,
                'requireSignedURLs' => false,
                'meta' => ['name' => $name !== '' ? $name : 'video'],
            ]);

        if (! $response->successful()) {
            throw new StreamException('Cloudflare Stream error (HTTP ' . $response->status() . '): ' . Str::limit($response->body(), 300));
        }

        $uid = $response->json('result.uid');
        $uploadUrl = $response->json('result.uploadURL');

        if (! is_string($uid) || ! is_string($uploadUrl)) {
            throw new StreamException('Cloudflare Stream returned an unexpected response.');
        }

        return ['uid' => $uid, 'uploadURL' => $uploadUrl];
    }

    /**
     * Current state of a video: its public watch URL (for sharing), thumbnail,
     * processing status and whether it's ready to play.
     *
     * @return array{uid: string, status: string, ready: bool, watchUrl: ?string, thumbnail: ?string, duration: ?float}
     */
    public function videoInfo(string $uid): array
    {
        $config = $this->configured();

        $response = $this->http
            ->withToken((string) $config->api_token)
            ->acceptJson()
            ->get($config->apiBase() . '/' . $uid);

        if (! $response->successful()) {
            throw new StreamException('Cloudflare Stream error (HTTP ' . $response->status() . '): ' . Str::limit($response->body(), 300));
        }

        $state = $response->json('result.status.state');
        $watch = $response->json('result.preview');
        $thumb = $response->json('result.thumbnail');
        $duration = $response->json('result.duration');

        return [
            'uid' => $uid,
            'status' => is_string($state) ? $state : 'unknown',
            'ready' => $response->json('result.readyToStream') === true,
            'watchUrl' => is_string($watch) ? $watch : null,
            'thumbnail' => is_string($thumb) ? $thumb : null,
            'duration' => is_numeric($duration) ? (float) $duration : null,
        ];
    }

    public function deleteVideo(string $uid): void
    {
        $config = $this->configured();

        $this->http
            ->withToken((string) $config->api_token)
            ->acceptJson()
            ->delete($config->apiBase() . '/' . $uid);
    }

    private function configured(): CloudflareStreamConfiguration
    {
        $config = CloudflareStreamConfiguration::current();

        if (! $config->isConfigured()) {
            throw new StreamException('Cloudflare Stream is not configured. Add an Account ID + API token under Settings → Cloudflare Stream and enable it.');
        }

        return $config;
    }
}
