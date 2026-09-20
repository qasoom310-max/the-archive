<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Assistant;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The in-ERP test chat's {@see ReplySink} — built so the same assistant could
 * be tried out from inside the ERP while Meta/WhatsApp wasn't set up yet. No
 * Graph API call, no media upload: a text reply is just captured for the page
 * to render, and a document is written to private storage under an
 * unguessable name (mirrors the pattern the WhatsApp receipt PNGs and the
 * rental videos already use) so the chat can offer it as a download.
 *
 * One instance per turn — {@see \Modules\WhatsApp\Livewire\AssistantChat}
 * reads {@see self::$messages} straight back off it once the turn is over.
 */
final class WebReplySink implements ReplySink
{
    private const DISK = 'local';

    public const DOCUMENT_DIR = 'whatsapp-assistant-chat';

    /** @var list<array{type: string, text: string, path?: string, filename?: string}> */
    public array $messages = [];

    use RecordsOutboundMessages;

    public function sendText(string $to, string $body, ?int $conversationId = null): bool
    {
        $this->messages[] = ['type' => 'text', 'text' => $body];
        $this->record($conversationId, 'text', $body);

        return true;
    }

    /**
     * $pdf is raw PDF bytes (what every PDF service in this app hands back),
     * not a path — see {@see ReplySink}.
     */
    public function sendDocument(string $to, string $pdf, string $filename, string $caption, ?int $conversationId = null): bool
    {
        // Keyed by the conversation id, never $to — that carries a "web:"
        // prefix + colon, which is not a legal path segment on every OS this
        // runs on.
        $path = self::DOCUMENT_DIR . '/' . ($conversationId ?? 0) . '/' . Str::random(40) . '.pdf';
        Storage::disk(self::DISK)->put($path, $pdf);

        $this->messages[] = ['type' => 'document', 'text' => $caption, 'path' => $path, 'filename' => $filename];
        $this->record($conversationId, 'document', $filename);

        return true;
    }
}
