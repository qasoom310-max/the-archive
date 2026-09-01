<?php

declare(strict_types=1);

namespace Modules\Limousine\Livewire;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Limousine\Models\LimoPortalConfiguration;

/**
 * Admin-only editor for the Wanaan WordPress service-order portal connection,
 * surfaced as the "Service Portal" tab of Settings.
 *
 * The shared secret is write-only from the UI: the stored value is never echoed
 * back — a blank field on save means "keep it". `enabled` is the master switch
 * the manager can flip OFF in one place if payments start misbehaving; it stays
 * OFF until the URL and secret are both set.
 */
#[Layout('components.layouts.app')]
#[Title('Service portal settings')]
final class LimoPortalSettings extends Component
{
    public string $portalUrl = '';

    public bool $enabled = false;

    /** Write-only: never pre-filled with the stored secret. */
    public string $sharedSecret = '';

    public bool $hasSecret = false;

    public bool $saved = false;

    public function mount(): void
    {
        $this->authorizeAdmin();

        $config = LimoPortalConfiguration::current();

        $this->portalUrl = (string) $config->portal_url;
        $this->enabled = (bool) $config->enabled;
        $this->hasSecret = (string) $config->shared_secret !== '';
    }

    private function authorizeAdmin(): void
    {
        $user = Auth::user();

        abort_unless($user instanceof User && $user->isAdmin(), 403, 'Settings are administrator-only.');
    }

    public function save(): void
    {
        $this->authorizeAdmin();

        $config = LimoPortalConfiguration::current();

        $config->portal_url = trim($this->portalUrl) ?: null;
        $config->enabled = $this->enabled;

        // Blank = keep the stored secret untouched.
        if (trim($this->sharedSecret) !== '') {
            $config->shared_secret = trim($this->sharedSecret);
        }

        if ($config->enabled && ($config->portal_url === null || (string) $config->shared_secret === '')) {
            $this->addError('enabled', 'A portal URL and shared secret are required before turning the portal on.');

            return;
        }

        $config->save();

        $this->sharedSecret = '';
        $this->hasSecret = (string) $config->shared_secret !== '';
        $this->saved = true;
    }

    public function updated(): void
    {
        $this->saved = false;
    }

    public function render(): View
    {
        return view('limousine::portal-settings', [
            'callbackUrl' => url('/limousine/payment-callback'),
        ]);
    }
}
