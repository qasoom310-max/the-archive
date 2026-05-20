<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Services;

use Illuminate\Contracts\Bus\Dispatcher;
use Modules\WhatsApp\Exceptions\WhatsAppException;
use Modules\WhatsApp\Jobs\SendWhatsAppMessage;
use Modules\WhatsApp\Models\WhatsAppConfiguration;
use Modules\WhatsApp\Models\WhatsAppMessageLog;

/**
 * Application-facing entry point for outbound WhatsApp messaging.
 *
 * Building the payload and queueing are cheap and synchronous; the
 * blocking Graph API round-trip happens inside the queued
 * {@see SendWhatsAppMessage} job, so callers (Chatter button,
 * event-triggered automations) never wait on Meta.
 */
final class WhatsAppService
{
    public function __construct(private readonly Dispatcher $bus)
    {
    }

    /**
     * Queue a Meta-approved template message.
     *
     * `$variables` maps positionally onto the template's body
     * placeholders ({{1}}, {{2}}, …) — this is the seed of the
     * Template Parser; richer header/button components come later.
     *
     * @param list<string|int|float> $variables ordered body placeholder values
     */
    public function sendTemplateMessage(
        string $to,
        string $template,
        array $variables = [],
        string $languageCode = 'en_US',
    ): void {
        $config = WhatsAppConfiguration::current();

        if (! $config->isConfigured()) {
            throw new WhatsAppException(
                'WhatsApp is not configured. Add credentials under Settings → WhatsApp and enable it.',
            );
        }

        $recipient = $this->normalizeNumber($to);

        if ($recipient === '') {
            throw new WhatsAppException('A destination phone number is required.');
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $recipient,
            'type' => 'template',
            'template' => [
                'name' => $template,
                'language' => ['code' => $languageCode],
                'components' => $this->buildBodyComponents($variables),
            ],
        ];

        $log = WhatsAppMessageLog::query()->create([
            'direction' => WhatsAppMessageLog::DIRECTION_OUTBOUND,
            'contact_number' => $recipient,
            'message_type' => 'template',
            'template_name' => $template,
            'status' => 'queued',
            'payload' => $payload,
        ]);

        $this->bus->dispatch(new SendWhatsAppMessage($recipient, $payload, $log->id));
    }

    /**
     * Map ordered values onto a single `body` component. An empty list
     * yields no components (template has no variables).
     *
     * @param list<string|int|float> $variables
     * @return list<array<string, mixed>>
     */
    private function buildBodyComponents(array $variables): array
    {
        if ($variables === []) {
            return [];
        }

        $parameters = [];

        foreach (array_values($variables) as $value) {
            $parameters[] = ['type' => 'text', 'text' => (string) $value];
        }

        return [['type' => 'body', 'parameters' => $parameters]];
    }

    /**
     * Meta expects the number in international format, digits only
     * (no '+', spaces or punctuation).
     */
    private function normalizeNumber(string $raw): string
    {
        return preg_replace('/\D+/', '', $raw) ?? '';
    }
}
