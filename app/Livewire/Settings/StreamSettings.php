<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Models\CloudflareStreamConfiguration;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Admin-only editor for the Cloudflare Stream connection (Account ID + API
 * token), surfaced as the "Cloudflare Stream" tab of Settings. The token is
 * write-only — never echoed back; a blank field on save keeps the stored value.
 */
#[Layout('components.layouts.app')]
#[Title('Cloudflare Stream settings')]
final class StreamSettings extends Component
{
    public string $accountId = '';

    public bool $enabled = false;

    // Write-only: never pre-filled with the stored token.
    public string $apiToken = '';

    public bool $hasApiToken = false;

    public bool $saved = false;

    public function mount(): void
    {
        $this->authorizeAdmin();

        $config = CloudflareStreamConfiguration::current();
        $this->accountId = (string) $config->account_id;
        $this->enabled = (bool) $config->enabled;
        $this->hasApiToken = (string) $config->api_token !== '';
    }

    private function authorizeAdmin(): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->isAdmin(), 403, 'Settings are administrator-only.');
    }

    public function save(): void
    {
        $this->authorizeAdmin();

        $this->validate([
            'accountId' => ['nullable', 'string', 'max:64'],
        ]);

        $config = CloudflareStreamConfiguration::current();
        $config->account_id = trim($this->accountId) ?: null;
        $config->enabled = $this->enabled;

        if (trim($this->apiToken) !== '') {
            $config->api_token = trim($this->apiToken);
        }

        if ($config->enabled && ($config->account_id === null || (string) $config->api_token === '')) {
            $this->addError('enabled', __('An Account ID and API token are required before enabling.'));

            return;
        }

        $config->save();

        $this->apiToken = '';
        $this->hasApiToken = (string) $config->api_token !== '';
        $this->saved = true;
    }

    public function updated(): void
    {
        $this->saved = false;
    }

    public function render(): View
    {
        return view('livewire.settings.stream-settings');
    }
}
