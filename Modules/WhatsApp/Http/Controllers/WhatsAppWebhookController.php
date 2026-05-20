<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Modules\WhatsApp\Events\IncomingWhatsAppMessage;
use Modules\WhatsApp\Models\WhatsAppConfiguration;
use Modules\WhatsApp\Models\WhatsAppMessageLog;

/**
 * Public, server-to-server endpoint Meta calls. It is intentionally
 * outside `auth` and CSRF-exempt (see bootstrap/app.php), so both sides
 * of its security are enforced here:
 *
 *  - GET  (subscription handshake): echo `hub.challenge` only when
 *    `hub.verify_token` equals our stored token.
 *  - POST (events): reject unless the `X-Hub-Signature-256` HMAC of the
 *    raw body (keyed by the app secret) matches.
 *
 * After signature checks, every payload is teed to the dedicated
 * `whatsapp` log channel (storage/logs/whatsapp.log) so Meta's exact
 * wire payload is inspectable while iterating. Status callbacks advance
 * the matching outbound {@see WhatsAppMessageLog} row (by `wamid`);
 * inbound customer messages are stored as new rows AND fire an
 * {@see IncomingWhatsAppMessage} event so downstream listeners
 * (Chatter, notifications, automations) can react.
 */
final class WhatsAppWebhookController
{
    public function verify(Request $request): Response
    {
        $config = WhatsAppConfiguration::current();
        $expected = (string) $config->webhook_verify_token;

        $mode = $this->param($request, 'hub.mode');
        $token = $this->param($request, 'hub.verify_token');
        $challenge = $this->param($request, 'hub.challenge');

        if ($expected === '' || $mode !== 'subscribe' || ! hash_equals($expected, $token)) {
            abort(403, 'WhatsApp webhook verification failed.');
        }

        return response($challenge, 200)->header('Content-Type', 'text/plain');
    }

    public function handle(Request $request): JsonResponse
    {
        $config = WhatsAppConfiguration::current();
        $secret = (string) $config->app_secret;
        $raw = $request->getContent();

        if ($secret === '' || ! $this->signatureValid($request, $raw, $secret)) {
            abort(403, 'Invalid WhatsApp webhook signature.');
        }

        /** @var mixed $decoded */
        $decoded = json_decode($raw, true);
        $payload = is_array($decoded) ? $decoded : [];

        // Tee the verified payload to a dedicated channel for live inspection
        // (storage/logs/whatsapp.log). Signature already validated above, so
        // anything that lands here is authentically from Meta.
        Log::channel('whatsapp')->info('Webhook payload received', ['payload' => $payload]);

        foreach ($this->changes($payload) as $value) {
            $this->applyStatuses($this->listOf($value, 'statuses'));
            $this->storeInbound($this->listOf($value, 'messages'), $this->metadata($value));
        }

        // Meta only needs a prompt 200; processing is best-effort.
        return response()->json(['received' => true]);
    }

    private function signatureValid(Request $request, string $raw, string $secret): bool
    {
        $header = $request->header('X-Hub-Signature-256');

        if (! is_string($header) || $header === '') {
            return false;
        }

        $expected = 'sha256=' . hash_hmac('sha256', $raw, $secret);

        return hash_equals($expected, $header);
    }

    /**
     * Meta status callbacks → advance the matching outbound row.
     *
     * @param list<array<string, mixed>> $statuses
     */
    private function applyStatuses(array $statuses): void
    {
        foreach ($statuses as $status) {
            $wamid = $this->str($status, 'id');
            $state = $this->str($status, 'status');

            if ($wamid === '' || $state === '') {
                continue;
            }

            WhatsAppMessageLog::query()
                ->where('wamid', $wamid)
                ->where('direction', WhatsAppMessageLog::DIRECTION_OUTBOUND)
                ->update(['status' => $state]);
        }
    }

    /**
     * Inbound customer replies → new `received` rows + a domain event so
     * downstream listeners (Chatter mirror, alerts, automations) can react
     * without coupling to the webhook plumbing.
     *
     * @param  list<array<string, mixed>>  $messages
     * @param  array<string, mixed>        $metadata  entry[].changes[].value.metadata (display_phone_number, etc.)
     */
    private function storeInbound(array $messages, array $metadata): void
    {
        foreach ($messages as $message) {
            $log = WhatsAppMessageLog::query()->create([
                'wamid' => $this->nullableStr($message, 'id'),
                'direction' => WhatsAppMessageLog::DIRECTION_INBOUND,
                'contact_number' => $this->nullableStr($message, 'from'),
                'message_type' => $this->nullableStr($message, 'type'),
                'status' => 'received',
                'payload' => $message,
            ]);

            event(new IncomingWhatsAppMessage(
                logId: $log->id,
                wamid: $this->nullableStr($message, 'id'),
                from: $this->nullableStr($message, 'from'),
                type: $this->nullableStr($message, 'type'),
                message: $message,
                metadata: $metadata,
            ));
        }
    }

    /**
     * Extract entry[].changes[].value.metadata as an associative array.
     *
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private function metadata(array $value): array
    {
        $meta = $value['metadata'] ?? null;

        return is_array($meta) ? $meta : [];
    }

    /**
     * Flatten entry[].changes[].value objects from the webhook payload.
     *
     * @param array<string, mixed> $payload
     * @return list<array<string, mixed>>
     */
    private function changes(array $payload): array
    {
        $out = [];
        $entries = $payload['entry'] ?? null;

        if (! is_array($entries)) {
            return $out;
        }

        foreach ($entries as $entry) {
            $changes = is_array($entry) ? ($entry['changes'] ?? null) : null;

            if (! is_array($changes)) {
                continue;
            }

            foreach ($changes as $change) {
                $value = is_array($change) ? ($change['value'] ?? null) : null;

                if (is_array($value)) {
                    /** @var array<string, mixed> $value */
                    $out[] = $value;
                }
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $value
     * @return list<array<string, mixed>>
     */
    private function listOf(array $value, string $key): array
    {
        $items = $value[$key] ?? null;

        if (! is_array($items)) {
            return [];
        }

        $out = [];

        foreach ($items as $item) {
            if (is_array($item)) {
                /** @var array<string, mixed> $item */
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function str(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * @param array<string, mixed> $data
     */
    private function nullableStr(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * Read a `hub.*` handshake param. PHP rewrites dots to underscores in
     * query keys, so accept both spellings.
     */
    private function param(Request $request, string $dotted): string
    {
        $underscored = str_replace('.', '_', $dotted);

        $value = $request->query($dotted, $request->query($underscored));

        return is_string($value) ? $value : '';
    }
}
