<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Livewire;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\WhatsApp\Models\WhatsAppConfiguration;

/**
 * Admin-only editor for the WhatsApp Business Cloud API credentials,
 * surfaced as the "WhatsApp" tab of Settings. Secrets are write-only
 * from the UI's point of view: stored values are never echoed back —
 * a blank secret field on save means "keep the existing value".
 */
#[Layout('components.layouts.app')]
#[Title('WhatsApp settings')]
final class WhatsAppSettings extends Component
{
    public string $phoneNumberId = '';

    public string $businessAccountId = '';

    public string $apiVersion = 'v21.0';

    public string $fromPhoneLabel = '';

    public bool $enabled = false;

    // Write-only: never pre-filled with the stored secret.
    public string $accessToken = '';

    public string $appSecret = '';

    public string $webhookVerifyToken = '';

    public bool $hasAccessToken = false;

    public bool $hasAppSecret = false;

    public bool $hasWebhookVerifyToken = false;

    public bool $saved = false;

    public function mount(): void
    {
        $this->authorizeAdmin();

        $config = WhatsAppConfiguration::current();

        $this->phoneNumberId = (string) $config->phone_number_id;
        $this->businessAccountId = (string) $config->business_account_id;
        $this->apiVersion = (string) $config->api_version !== '' ? (string) $config->api_version : 'v21.0';
        $this->fromPhoneLabel = (string) $config->from_phone_label;
        $this->enabled = (bool) $config->enabled;

        $this->hasAccessToken = (string) $config->access_token !== '';
        $this->hasAppSecret = (string) $config->app_secret !== '';
        $this->hasWebhookVerifyToken = (string) $config->webhook_verify_token !== '';
    }

    private function authorizeAdmin(): void
    {
        $user = Auth::user();

        abort_unless($user instanceof User && $user->isAdmin(), 403, 'Settings are administrator-only.');
    }

    public function save(): void
    {
        $this->authorizeAdmin();

        $config = WhatsAppConfiguration::current();

        $config->phone_number_id = trim($this->phoneNumberId) ?: null;
        $config->business_account_id = trim($this->businessAccountId) ?: null;
        $config->api_version = trim($this->apiVersion) ?: 'v21.0';
        $config->from_phone_label = trim($this->fromPhoneLabel) ?: null;
        $config->enabled = $this->enabled;

        // Blank = keep the stored secret untouched.
        if (trim($this->accessToken) !== '') {
            $config->access_token = trim($this->accessToken);
        }

        if (trim($this->appSecret) !== '') {
            $config->app_secret = trim($this->appSecret);
        }

        if (trim($this->webhookVerifyToken) !== '') {
            $config->webhook_verify_token = trim($this->webhookVerifyToken);
        }

        if ($config->enabled && ($config->phone_number_id === null || (string) $config->access_token === '')) {
            $this->addError('enabled', 'A phone number id and access token are required before enabling WhatsApp.');

            return;
        }

        $config->save();

        $this->accessToken = '';
        $this->appSecret = '';
        $this->webhookVerifyToken = '';
        $this->hasAccessToken = (string) $config->access_token !== '';
        $this->hasAppSecret = (string) $config->app_secret !== '';
        $this->hasWebhookVerifyToken = (string) $config->webhook_verify_token !== '';
        $this->saved = true;
    }

    public function updated(): void
    {
        $this->saved = false;
    }

    public function render(): View
    {
        return view('whatsapp::settings', [
            'webhookUrl' => url('/whatsapp/webhook'),
        ]);
    }
}
