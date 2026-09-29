<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Livewire;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\WhatsApp\Assistant\StaffAssistant;
use Modules\WhatsApp\Assistant\WebReplySink;
use Modules\WhatsApp\Models\AssistantConfiguration;
use Modules\WhatsApp\Models\AssistantMemory;
use Modules\WhatsApp\Models\AssistantStaff;
use Modules\WhatsApp\Models\Conversation;

/**
 * An in-ERP stand-in for WhatsApp: the same assistant, the same rules, tried
 * from a chat box on the page instead of a phone — built so it could be used
 * and reviewed before Meta/WhatsApp Business is set up. This is not a second
 * assistant: it drives the exact same {@see StaffAssistant} the real webhook
 * does, only replying through a {@see WebReplySink} instead of Meta, so
 * anything that works here works identically once WhatsApp is switched on.
 *
 * Anyone who could use the real bot may use this one: an admin (to set it up
 * and try it), or a phone number in `whatsapp_assistant_staff` mapped to
 * their own account. Nobody else may open the page.
 */
#[Layout('components.layouts.app')]
#[Title('Assistant chat')]
final class AssistantChat extends Component
{
    public string $text = '';

    /** Set right after a document turn; cleared once downloaded or once the next message is sent. */
    public ?string $pendingDownloadPath = null;

    public ?string $pendingDownloadFilename = null;

    public function mount(): void
    {
        $this->authorizeSelf();
    }

    public function send(): void
    {
        $user = $this->authorizeSelf();

        $this->validate(['text' => ['required', 'string', 'max:2000']]);

        $sink = new WebReplySink();
        /** @var StaffAssistant $assistant */
        $assistant = app()->make(StaffAssistant::class, ['messenger' => $sink]);
        $assistant->converseAsWebUser($user, trim($this->text));

        $this->text = '';
        $this->pendingDownloadPath = null;
        $this->pendingDownloadFilename = null;

        foreach ($sink->messages as $message) {
            if ($message['type'] === 'document' && isset($message['path'], $message['filename'])) {
                $this->pendingDownloadPath = $message['path'];
                $this->pendingDownloadFilename = $message['filename'];
            }
        }
    }

    /** Delete one of the viewer's own saved notes. */
    public function forgetMemory(int $id): void
    {
        $user = $this->authorizeSelf();

        AssistantMemory::query()->where('user_id', $user->id)->whereKey($id)->delete();
    }

    public function render(): View
    {
        $user = $this->authorizeSelf();
        $conversation = Conversation::query()->where('wa_id', 'web:' . $user->id)->first();
        $history = $conversation?->history;

        return view('whatsapp::assistant-chat', [
            'history' => $history ?? [],
            // What the assistant remembers for this person, beyond the recent
            // chat — the same notes it reads on WhatsApp.
            'memories' => AssistantMemory::forUser((int) $user->id),
            'ready' => AssistantConfiguration::current()->isReady(),
            // Rides in the query string like `BackupDownloadController`'s —
            // the actual download is a plain controller (a Livewire action
            // can't hand the browser a "Save As" without the SPA getting in
            // the way), re-checked there against this user's own folder.
            'downloadUrl' => $this->pendingDownloadPath !== null
                ? url('/app/settings/whatsapp-assistant/chat/download') . '?' . http_build_query([
                    'path' => $this->pendingDownloadPath,
                    'filename' => $this->pendingDownloadFilename ?? 'document.pdf',
                ])
                : null,
        ]);
    }

    private function authorizeSelf(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        $mapped = AssistantStaff::query()->where('user_id', $user->id)->where('active', true)->exists();
        abort_unless($user->isAdmin() || $mapped, 403, 'The assistant is not available to this account.');

        return $user;
    }
}
