<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Erp\Tenancy\WorkspaceManager;
use App\Models\AppDevice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

/**
 * Hands staff-app notifications to Expo's push service, which delivers them to
 * Android and iPhone. A phone Expo reports as gone (the app was uninstalled)
 * is forgotten — in the database the notification came from, since the queue
 * itself runs on Main.
 */
final class SendExpoPush implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const ENDPOINT = 'https://exp.host/--/api/v2/push/send';

    public int $tries = 3;

    /**
     * @param  list<array<string, mixed>>  $messages  Expo push messages, `to` = the token
     */
    public function __construct(
        public readonly array $messages,
        public readonly ?int $workspaceId = null,
    ) {
    }

    public function handle(HttpFactory $http): void
    {
        $gone = [];

        // Expo takes at most 100 messages a request.
        foreach (array_chunk($this->messages, 100) as $chunk) {
            $request = $http->acceptJson()->asJson()->timeout(20);
            $token = config('services.expo.access_token');
            if (is_string($token) && $token !== '') {
                $request = $request->withToken($token);
            }

            $response = $request->post(self::ENDPOINT, $chunk);
            if ($response->serverError() || $response->status() === 429) {
                // Theirs, and passing: retried (tries / backoff).
                throw new RuntimeException('Expo push failed: HTTP ' . $response->status());
            }

            $tickets = $response->json('data');
            foreach (is_array($tickets) ? array_values($tickets) : [] as $i => $ticket) {
                $error = is_array($ticket) ? data_get($ticket, 'details.error') : null;
                if ($error === 'DeviceNotRegistered' && isset($chunk[$i]['to'])) {
                    $gone[] = (string) $chunk[$i]['to'];
                }
            }
        }

        if ($gone !== []) {
            app(WorkspaceManager::class)->runFor($this->workspaceId, static function () use ($gone): void {
                AppDevice::query()->whereIn('token', $gone)->delete();
            });
        }
    }

    /** Seconds between tries. */
    public function backoff(): int
    {
        return 30;
    }
}
