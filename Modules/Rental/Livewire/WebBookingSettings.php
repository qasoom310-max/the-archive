<?php

declare(strict_types=1);

namespace Modules\Rental\Livewire;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Rental\Models\RentalPortalConfiguration;

/**
 * Admin-only editor for the website's booking connection, surfaced as the
 * "Web Bookings" tab of Settings.
 *
 * The shared secret is write-only from the UI: the stored value is never shown
 * again, and a blank field on save means "keep the one you have". `enabled` is
 * the switch that decides whether the endpoint accepts anything at all, and it
 * stays off until a secret exists — an endpoint facing the open internet with
 * nothing to verify against would take whatever arrived.
 *
 * The secret can be GENERATED here rather than invented by hand. The old
 * integration's credentials were typed into the plugin file and shipped with
 * it, which is how they ended up readable by anyone holding a copy.
 */
#[Layout('components.layouts.app')]
#[Title('Web booking settings')]
final class WebBookingSettings extends Component
{
    public bool $enabled = false;

    /** Write-only: never pre-filled with the stored secret. */
    public string $sharedSecret = '';

    public bool $hasSecret = false;

    public bool $saved = false;

    /** Shown ONCE, right after generating, so it can be copied to WordPress. */
    public string $generated = '';

    public function mount(): void
    {
        $this->authorizeAdmin();

        $config = RentalPortalConfiguration::current();

        $this->enabled = (bool) $config->enabled;
        $this->hasSecret = (string) $config->shared_secret !== '';
    }

    private function authorizeAdmin(): void
    {
        $user = Auth::user();

        abort_unless($user instanceof User && $user->isAdmin(), 403, 'Settings are administrator-only.');
    }

    /**
     * A secret nobody had to think up. 64 hex characters from the CSPRNG —
     * long enough that guessing it is not a strategy, and it is shown once
     * here rather than stored anywhere a person would read it twice.
     */
    public function generateSecret(): void
    {
        $this->authorizeAdmin();

        $this->generated = bin2hex(random_bytes(32));
        $this->sharedSecret = $this->generated;
        $this->saved = false;
    }

    public function save(): void
    {
        $this->authorizeAdmin();

        $config = RentalPortalConfiguration::current();

        if (trim($this->sharedSecret) !== '') {
            $config->shared_secret = trim($this->sharedSecret);
        }

        $config->enabled = $this->enabled;

        if ($config->enabled && (string) $config->shared_secret === '') {
            $this->addError('enabled', __('Set a shared secret before switching bookings on.'));

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
        return view('rental::web-booking-settings', [
            // What the plugin is pointed at, spelled out so nobody has to
            // work it out: the workspace is part of it, because one ERP
            // serves several businesses from one address.
            'endpoint' => url('/rental/web-booking'),
            'workspaceId' => $this->workspaceId(),
        ]);
    }

    /**
     * The database this website's bookings belong to — the `ws` the plugin
     * sends. Main has no workspace row of its own, and a database that cannot
     * be resolved is reported as Main rather than as a number that would send
     * bookings somewhere else.
     */
    private function workspaceId(): ?int
    {
        try {
            $current = app(\App\Erp\Tenancy\WorkspaceManager::class)->current();

            return $current->is_main ? null : (int) $current->id;
        } catch (\Throwable) {
            return null;
        }
    }
}
