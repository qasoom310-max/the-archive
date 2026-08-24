<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Jobs;

use App\Erp\Tenancy\WorkspaceManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\WhatsApp\Exceptions\WhatsAppException;
use Modules\WhatsApp\Models\WhatsAppConfiguration;
use Modules\WhatsApp\Models\WhatsAppMessageLog;

/**
 * Performs the actual Graph API call off the request thread, so the UI
 * never blocks on Meta. The fully-built message body is passed in; the
 * job only resolves the (latest) credentials and POSTs. A non-2xx
 * response throws so the job retries, then lands in `failed_jobs`.
 *
 * The queue connection is pinned to the Main database, so a message queued from
 * a tenant workspace RUNS in Main's context. It therefore carries the
 * originating `workspaceId` and re-activates that workspace before sending —
 * otherwise it would send with Main's WhatsApp credentials and stamp
 * sent/failed onto an unrelated message row (the same bug WooCommerce fixed).
 */
final class SendWhatsAppMessage implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /**
     * @param array<string, mixed> $payload Graph API message body
     */
    public function __construct(
        public readonly string $to,
        public readonly array $payload,
        public readonly ?int $logId = null,
        public readonly ?int $workspaceId = null,
    ) {
    }

    public function handle(HttpFactory $http): void
    {
        app(WorkspaceManager::class)->runFor($this->workspaceId, function () use ($http): void {
            $this->send($http);
        });
    }

    private function send(HttpFactory $http): void
    {
        $config = WhatsAppConfiguration::current();

        if (! $config->isConfigured()) {
            $this->markFailed('WhatsApp configuration missing or disabled at send time.');

            throw new WhatsAppException('WhatsApp configuration missing or disabled at send time.');
        }

        $response = $http->acceptJson()
            ->withToken((string) $config->access_token)
            ->post($config->graphEndpoint(), $this->payload);

        if (! $response->successful()) {
            $message = sprintf(
                'WhatsApp API error sending to %s (HTTP %d): %s',
                $this->to,
                $response->status(),
                $response->body(),
            );
            $this->markFailed($message);

            throw new WhatsAppException($message);
        }

        $wamid = $response->json('messages.0.id');
        $this->markSent(is_string($wamid) ? $wamid : null);
    }

    private function markSent(?string $wamid): void
    {
        $this->log()?->update(['status' => 'sent', 'wamid' => $wamid]);
    }

    private function markFailed(string $error): void
    {
        $this->log()?->update(['status' => 'failed', 'error' => $error]);
    }

    private function log(): ?WhatsAppMessageLog
    {
        if ($this->logId === null) {
            return null;
        }

        return WhatsAppMessageLog::query()->find($this->logId);
    }

    /**
     * Seconds to wait between retries (Meta rate-limit friendly).
     */
    public function backoff(): int
    {
        return 30;
    }
}
