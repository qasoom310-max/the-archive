<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Assistant;

/**
 * Where the assistant's replies go. {@see StaffAssistant} talks to this
 * interface, never to Meta directly, so the same conversation logic can be
 * driven from a channel that isn't WhatsApp — the in-ERP test chat
 * ({@see WebReplySink}) is the first one, built while Meta wasn't ready yet.
 *
 * A confirmed document reply passes raw PDF bytes, not a path — that is what
 * {@see \Modules\Limousine\Services\ServiceOrderPdf} and its siblings already
 * hand back, and it is also what Meta's media upload wants.
 */
interface ReplySink
{
    public function sendText(string $to, string $body, ?int $conversationId = null): bool;

    public function sendDocument(string $to, string $pdf, string $filename, string $caption, ?int $conversationId = null): bool;
}
