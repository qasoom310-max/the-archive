<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Assistant;

use Modules\WhatsApp\Models\ConversationMessage;
use Throwable;

/**
 * Every {@see ReplySink} keeps the same transcript row on the way out,
 * regardless of where the message actually went — the channel decides how to
 * DELIVER a reply, not whether it gets remembered.
 */
trait RecordsOutboundMessages
{
    private function record(?int $conversationId, string $type, string $body): void
    {
        try {
            ConversationMessage::query()->create([
                'conversation_id' => $conversationId,
                'direction' => ConversationMessage::OUT,
                'type' => $type,
                'body' => $body,
            ]);
        } catch (Throwable) {
            // The transcript is a record, not a dependency.
        }
    }
}
