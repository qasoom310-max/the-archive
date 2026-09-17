<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Http\Controllers;

use App\Erp\Tenancy\WorkspaceManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Schema;
use Modules\WhatsApp\Assistant\StaffAssistant;
use Modules\WhatsApp\Models\WhatsAppConfiguration;

use function Illuminate\Support\defer;

/**
 * Meta's webhook for the WhatsApp staff assistant, one URL per database:
 *
 *   GET|POST /integrations/whatsapp/{workspace}/webhook
 *
 * The workspace is in the path because the Meta credentials (verify token, app
 * secret) are per database — the request has to be checked against the RIGHT
 * database's secret before anything is trusted.
 *
 * POST always answers 200 quickly, and the reply is worked out AFTER the
 * response has gone (`defer`): Meta retries anything slow, and a chat turn with
 * the AI can take several seconds. The work still happens in this same PHP
 * worker — the queue only drains once a minute here, far too slow for a chat.
 */
final class AssistantWebhookController
{
    public function __construct(private readonly WorkspaceManager $workspaces)
    {
    }

    public function verify(Request $request, string $workspace): Response
    {
        $this->resolve($workspace);

        return $this->workspaces->runFor((int) $workspace, function () use ($request): Response {
            $expected = (string) WhatsAppConfiguration::current()->webhook_verify_token;

            $mode = $this->param($request, 'hub.mode');
            $token = $this->param($request, 'hub.verify_token');

            if ($expected === '' || $mode !== 'subscribe' || ! hash_equals($expected, $token)) {
                abort(403, 'WhatsApp webhook verification failed.');
            }

            return response($this->param($request, 'hub.challenge'), 200)->header('Content-Type', 'text/plain');
        });
    }

    public function handle(Request $request, StaffAssistant $assistant, string $workspace): JsonResponse
    {
        $this->resolve($workspace);
        $raw = $request->getContent();
        $id = (int) $workspace;

        $messages = $this->workspaces->runFor($id, function () use ($request, $raw): ?array {
            $secret = (string) WhatsAppConfiguration::current()->app_secret;
            $header = $request->header('X-Hub-Signature-256');

            if ($secret === '' || ! is_string($header) || ! hash_equals('sha256=' . hash_hmac('sha256', $raw, $secret), $header)) {
                return null;
            }

            return $this->inbound($raw);
        });

        if ($messages === null) {
            abort(403, 'Invalid WhatsApp webhook signature.');
        }

        if ($messages !== []) {
            defer(function () use ($assistant, $messages, $id): void {
                $this->workspaces->runFor($id, function () use ($assistant, $messages): void {
                    foreach ($messages as $message) {
                        $assistant->handle($message['from'], $message['id'], $message['type'], $message['text']);
                    }
                });
            });
        }

        return response()->json(['received' => true]);
    }

    /**
     * An unknown or deleted database must never fall through to Main — the
     * same rule the pricing API follows.
     */
    private function resolve(string $workspace): void
    {
        if (Schema::hasTable('workspaces') && $this->workspaces->find((int) $workspace) === null) {
            abort(404);
        }
    }

    /**
     * The inbound messages in a Meta payload (status callbacks are ignored).
     *
     * @return list<array{from: string, id: string, type: string, text: string}>
     */
    private function inbound(string $raw): array
    {
        $payload = json_decode($raw, true);
        if (! is_array($payload) || ! is_array($payload['entry'] ?? null)) {
            return [];
        }

        $out = [];
        foreach ($payload['entry'] as $entry) {
            foreach ((is_array($entry) && is_array($entry['changes'] ?? null)) ? $entry['changes'] : [] as $change) {
                $value = is_array($change) && is_array($change['value'] ?? null) ? $change['value'] : [];
                foreach (is_array($value['messages'] ?? null) ? $value['messages'] : [] as $message) {
                    if (! is_array($message)) {
                        continue;
                    }

                    $type = is_string($message['type'] ?? null) ? $message['type'] : '';
                    $text = '';
                    if ($type === 'text' && is_array($message['text'] ?? null) && is_string($message['text']['body'] ?? null)) {
                        $text = $message['text']['body'];
                    } elseif ($type === 'button' && is_array($message['button'] ?? null) && is_string($message['button']['text'] ?? null)) {
                        $type = 'text';
                        $text = $message['button']['text'];
                    }

                    $out[] = [
                        'from' => is_string($message['from'] ?? null) ? $message['from'] : '',
                        'id' => is_string($message['id'] ?? null) ? $message['id'] : '',
                        'type' => $type,
                        'text' => $text,
                    ];
                }
            }
        }

        return $out;
    }

    private function param(Request $request, string $dotted): string
    {
        $value = $request->query($dotted, $request->query(str_replace('.', '_', $dotted)));

        return is_string($value) ? $value : '';
    }
}
