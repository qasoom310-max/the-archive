<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Modules\WhatsApp\Assistant\WebReplySink;
use Modules\WhatsApp\Models\AssistantStaff;
use Modules\WhatsApp\Models\Conversation;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams back a PDF the in-ERP assistant test chat produced (see
 * {@see \Modules\WhatsApp\Assistant\WebReplySink} and
 * {@see \Modules\WhatsApp\Livewire\AssistantChat}).
 *
 * Same shape as `App\Http\Controllers\BackupDownloadController`: the path
 * rides in the query string — it already carries an unguessable random
 * token — and is re-checked here against the CALLER's OWN conversation
 * folder before anything is streamed, so one signed-in user can never fetch
 * a document another one's chat generated.
 */
final class AssistantChatDownloadController
{
    public function __invoke(Request $request): StreamedResponse
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        $mapped = AssistantStaff::query()->where('user_id', $user->id)->where('active', true)->exists();
        abort_unless($user->isAdmin() || $mapped, 403);

        $conversation = Conversation::query()->where('wa_id', 'web:' . $user->id)->first();
        $conversationId = $conversation?->id;
        $path = (string) $request->query('path', '');
        $filename = (string) $request->query('filename', 'document.pdf');

        $prefix = WebReplySink::DOCUMENT_DIR . '/' . ($conversationId ?? 0) . '/';
        abort_unless($path !== '' && str_starts_with($path, $prefix) && Storage::disk('local')->exists($path), 404);

        $bytes = (string) Storage::disk('local')->get($path);

        return response()->streamDownload(static function () use ($bytes): void {
            echo $bytes;
        }, $filename, ['Content-Type' => 'application/pdf']);
    }
}
