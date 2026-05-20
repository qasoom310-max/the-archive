<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired by {@see \Modules\WhatsApp\Http\Controllers\WhatsAppWebhookController}
 * once per inbound customer message, after the row is persisted to
 * `whatsapp_messages_log`. Listen to mirror to Chatter, trigger
 * automations, alert agents, etc. — keeps the webhook plumbing
 * decoupled from downstream business logic.
 *
 * `$message` is the raw Meta message object (whatever shape Meta sends:
 * text/image/audio/document/interactive/...). `$metadata` is
 * `entry[].changes[].value.metadata` from the envelope — typically
 * `display_phone_number` and `phone_number_id` of the business number
 * the customer wrote to.
 */
final class IncomingWhatsAppMessage
{
    use Dispatchable;

    /**
     * @param array<string, mixed> $message  Meta's message object
     * @param array<string, mixed> $metadata entry.changes.value.metadata
     */
    public function __construct(
        public readonly int $logId,
        public readonly ?string $wamid,
        public readonly ?string $from,
        public readonly ?string $type,
        public readonly array $message,
        public readonly array $metadata,
    ) {
    }
}
