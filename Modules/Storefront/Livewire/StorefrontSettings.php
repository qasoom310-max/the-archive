<?php

declare(strict_types=1);

namespace Modules\Storefront\Livewire;

use App\Erp\Tenancy\WorkspaceManager;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Storefront\Models\StorefrontConfiguration;
use Throwable;

/**
 * Settings → Online store (administrators only). The switch, the shared
 * secret the shop website signs with (generated here, shown once, stored
 * encrypted, never shown again) and the shop details the website displays.
 */
#[Layout('components.layouts.app')]
#[Title('Online store settings')]
final class StorefrontSettings extends Component
{
    public bool $enabled = false;

    public bool $hasSecret = false;

    /** Shown once, right after generating, to copy into the shop website. */
    public string $generated = '';

    public string $whatsappNumber = '';

    public string $deliveryCharge = '0';

    public string $freeDeliveryOver = '';

    public bool $saved = false;

    public function mount(): void
    {
        $this->authorizeAdmin();

        $config = StorefrontConfiguration::current();
        $this->enabled = (bool) $config->enabled;
        $this->hasSecret = (string) $config->shared_secret !== '';
        $this->whatsappNumber = (string) $config->whatsapp_number;
        $this->deliveryCharge = (string) ($config->delivery_charge ?? 0);
        $this->freeDeliveryOver = $config->free_delivery_over !== null ? (string) $config->free_delivery_over : '';
    }

    private function authorizeAdmin(): void
    {
        $user = Auth::user();

        abort_unless($user instanceof User && $user->isAdmin(), 403, 'Settings are administrator-only.');
    }

    /**
     * A new secret, saved at once. The old one stops working immediately, so
     * the website must be given the new one straight away.
     */
    public function generateSecret(): void
    {
        $this->authorizeAdmin();

        $secret = bin2hex(random_bytes(32));
        $config = StorefrontConfiguration::current();
        $config->shared_secret = $secret;
        $config->save();

        $this->generated = $secret;
        $this->hasSecret = true;
        $this->saved = false;
    }

    public function save(): void
    {
        $this->authorizeAdmin();

        $this->validate([
            'whatsappNumber' => ['nullable', 'string', 'max:32', 'regex:/^[+\d\s-]*$/'],
            'deliveryCharge' => ['required', 'numeric', 'min:0', 'max:1000'],
            'freeDeliveryOver' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ]);

        $config = StorefrontConfiguration::current();

        if ($this->enabled && (string) $config->shared_secret === '') {
            $this->addError('enabled', __('Generate a shared secret before switching the online store on.'));

            return;
        }

        $config->enabled = $this->enabled;
        $config->whatsapp_number = trim($this->whatsappNumber) !== '' ? trim($this->whatsappNumber) : null;
        $config->delivery_charge = round((float) $this->deliveryCharge, 3);
        $config->free_delivery_over = trim($this->freeDeliveryOver) !== '' ? round((float) $this->freeDeliveryOver, 3) : null;
        $config->save();

        $this->saved = true;
    }

    public function updated(): void
    {
        $this->saved = false;
    }

    public function render(): View
    {
        $ws = $this->workspaceId();

        return view('storefront::settings', [
            'baseUrl' => url('/storefront/v1/' . $ws),
            'workspaceId' => $ws,
        ]);
    }

    /** This database's number in the address; 0 is Main. */
    private function workspaceId(): int
    {
        try {
            $current = app(WorkspaceManager::class)->current();

            return $current->is_main ? 0 : (int) $current->id;
        } catch (Throwable) {
            return 0;
        }
    }
}
