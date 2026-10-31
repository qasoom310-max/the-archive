<?php

declare(strict_types=1);

namespace Modules\Limousine\Livewire;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Limousine\Models\LimoCrewAlertConfiguration;
use Modules\Limousine\Services\CrewNotices;
use Modules\WhatsApp\Models\WhatsAppConfiguration;

/**
 * Admin-only settings for the automatic driver-details messages, the
 * "Driver details" tab of Settings: the operations team's WhatsApp numbers,
 * the owner's number, and the switch. The switch stays off until both numbers
 * are in, and is meant to be turned on once Meta has approved the two message
 * templates this page lists ({@see CrewNotices}).
 */
#[Layout('components.layouts.app')]
#[Title('Driver details')]
final class LimoCrewAlertSettings extends Component
{
    public bool $enabled = false;

    /** One WhatsApp number per line. */
    public string $operationsNumbers = '';

    public string $ownerNumber = '';

    public bool $saved = false;

    public function mount(): void
    {
        $this->authorizeAdmin();

        $config = LimoCrewAlertConfiguration::current();

        $this->enabled = (bool) $config->enabled;
        $this->operationsNumbers = (string) $config->operations_numbers;
        $this->ownerNumber = (string) $config->owner_number;
    }

    private function authorizeAdmin(): void
    {
        $user = Auth::user();

        abort_unless($user instanceof User && $user->isAdmin(), 403, 'Settings are administrator-only.');
    }

    public function save(): void
    {
        $this->authorizeAdmin();
        $this->resetErrorBag();

        $config = LimoCrewAlertConfiguration::current();
        $config->operations_numbers = trim($this->operationsNumbers) !== '' ? trim($this->operationsNumbers) : null;
        $config->owner_number = trim($this->ownerNumber) !== '' ? trim($this->ownerNumber) : null;
        $config->enabled = $this->enabled;

        if ($config->enabled && $config->operationsNumbers() === []) {
            $this->addError('operationsNumbers', __('Add at least one operations WhatsApp number before turning this on.'));

            return;
        }

        if ($config->enabled && $config->ownerNumber() === '') {
            $this->addError('ownerNumber', __('Add the number to alert when a trip misses the 12 hours, before turning this on.'));

            return;
        }

        $config->save();
        $this->saved = true;
    }

    public function updated(): void
    {
        $this->saved = false;
    }

    public function render(): View
    {
        return view('limousine::crew-alert-settings', [
            'customerTemplate' => CrewNotices::TEMPLATE_CUSTOMER,
            'reminderTemplate' => CrewNotices::TEMPLATE_REMINDER,
            // Messages go out on THIS database's WhatsApp connection; without
            // one, switching on would send nothing and say nothing.
            'whatsappReady' => Schema::hasTable('whatsapp_configuration') && WhatsAppConfiguration::current()->isConfigured(),
        ]);
    }
}
