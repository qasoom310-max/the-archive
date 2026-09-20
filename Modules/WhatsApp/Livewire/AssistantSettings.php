<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Livewire;

use App\Erp\Activity\ActivityLogger;
use App\Erp\Tenancy\WorkspaceManager;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\WhatsApp\Models\AssistantConfiguration;
use Modules\WhatsApp\Models\AssistantStaff;
use Modules\WhatsApp\Models\WhatsAppConfiguration;
use Throwable;

/**
 * Admin-only settings for the WhatsApp staff assistant: the master switch, the
 * AI key and model, and which WhatsApp numbers may use it as which ERP user.
 *
 * The Meta credentials are NOT here — they are the WhatsApp tab's. The AI key
 * is write-only: never echoed back, and a blank box on save keeps the stored
 * one. English by design, like the other integration tabs.
 */
#[Layout('components.layouts.app')]
#[Title('WhatsApp assistant')]
final class AssistantSettings extends Component
{
    public bool $enabled = false;

    public string $aiModel = AssistantConfiguration::DEFAULT_MODEL;

    public string $aiApiKey = '';

    public bool $hasApiKey = false;

    public string $newPhone = '';

    public ?int $newUserId = null;

    public bool $saved = false;

    public function mount(): void
    {
        $this->authorizeAdmin();

        $config = AssistantConfiguration::current();
        $this->enabled = (bool) $config->enabled;
        $this->aiModel = $config->model();
        $this->hasApiKey = trim((string) $config->ai_api_key) !== '';
    }

    public function save(): void
    {
        $this->authorizeAdmin();

        $this->validate([
            'aiModel' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9.\-]+$/'],
        ]);

        $config = AssistantConfiguration::current();
        $config->ai_model = trim($this->aiModel);
        $config->enabled = $this->enabled;

        if (trim($this->aiApiKey) !== '') {
            $config->ai_api_key = trim($this->aiApiKey);
        }

        if ($config->enabled && trim((string) $config->ai_api_key) === '') {
            $this->addError('enabled', 'Add the Claude API key before switching the assistant on.');

            return;
        }

        $config->save();
        app(ActivityLogger::class)->log('settings_updated', 'WhatsApp assistant', $config->enabled ? 'Assistant on' : 'Assistant off');

        $this->aiApiKey = '';
        $this->hasApiKey = trim((string) $config->ai_api_key) !== '';
        $this->saved = true;
    }

    public function addStaff(): void
    {
        $this->authorizeAdmin();

        $this->newPhone = AssistantStaff::normalise($this->newPhone);

        $this->validate([
            'newPhone' => ['required', 'string', 'min:8', 'max:15', 'unique:whatsapp_assistant_staff,phone'],
            'newUserId' => ['required', 'integer', 'exists:users,id'],
        ], [
            'newPhone.min' => 'Enter the full number with its country code, e.g. 97338467744.',
            'newPhone.unique' => 'That number is already authorised.',
        ]);

        $staff = AssistantStaff::query()->create(['phone' => $this->newPhone, 'user_id' => (int) $this->newUserId, 'active' => true]);
        app(ActivityLogger::class)->log('settings_updated', 'WhatsApp assistant', 'Authorised +' . $staff->phone);

        $this->newPhone = '';
        $this->newUserId = null;
    }

    public function removeStaff(int $id): void
    {
        $this->authorizeAdmin();

        $staff = AssistantStaff::query()->find($id);
        if ($staff === null) {
            return;
        }

        app(ActivityLogger::class)->log('settings_updated', 'WhatsApp assistant', 'Removed +' . $staff->phone);
        $staff->delete();
    }

    public function updated(string $name): void
    {
        if (! in_array($name, ['newPhone', 'newUserId'], true)) {
            $this->saved = false;
        }
    }

    public function render(): View
    {
        $workspaceId = null;
        try {
            $workspaceId = Schema::hasTable('workspaces') ? (int) app(WorkspaceManager::class)->current()->id : null;
        } catch (Throwable) {
            $workspaceId = null;
        }

        $meta = WhatsAppConfiguration::current();

        return view('whatsapp::assistant-settings', [
            'staff' => AssistantStaff::query()->with('user')->orderBy('phone')->get(),
            'users' => User::query()->orderBy('name')->get(['id', 'name', 'email']),
            'webhookUrl' => $workspaceId !== null ? url('/integrations/whatsapp/' . $workspaceId . '/webhook') : null,
            'metaReady' => (string) $meta->phone_number_id !== '' && (string) $meta->access_token !== ''
                && (string) $meta->app_secret !== '' && (string) $meta->webhook_verify_token !== '',
        ]);
    }

    private function authorizeAdmin(): void
    {
        $user = Auth::user();

        abort_unless($user instanceof User && $user->isAdmin(), 403, 'Settings are administrator-only.');
    }
}
