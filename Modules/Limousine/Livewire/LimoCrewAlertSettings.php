<?php

declare(strict_types=1);

namespace Modules\Limousine\Livewire;

use App\Erp\Admin\TripStaffSwitches;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Limousine\Models\LimoCrewAlertConfiguration;
use Modules\Limousine\Services\CrewNotices;
use Modules\Limousine\Support\TripDetailsLock;
use Modules\WhatsApp\Models\WhatsAppConfiguration;

/**
 * Admin-only settings for the automatic driver-details messages, the
 * "Driver details" tab of Settings: the operations team's WhatsApp numbers,
 * the owner's number, and the switch. The switch stays off until both numbers
 * are in, and is meant to be turned on once Meta has approved the two message
 * templates this page lists ({@see CrewNotices}).
 *
 * Also who sees a trip's details only once it has a car and a driver
 * ({@see TripDetailsLock}) — ticked per person, by the owner alone (a super
 * admin); other admins see the list read-only.
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

    /**
     * Ticked staff: their trip details stay hidden until a car and a driver
     * are assigned. Ids in THIS database — inside a workspace, the account a
     * person works as is that workspace's copy.
     *
     * @var list<int|string>
     */
    public array $lockedUserIds = [];

    public bool $staffSaved = false;

    public function mount(): void
    {
        $this->authorizeAdmin();

        $config = LimoCrewAlertConfiguration::current();

        $this->enabled = (bool) $config->enabled;
        $this->operationsNumbers = (string) $config->operations_numbers;
        $this->ownerNumber = (string) $config->owner_number;

        $this->lockedUserIds = Schema::hasColumn('users', 'trip_details_locked')
            ? User::query()->where('trip_details_locked', true)->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all()
            : [];
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

    /**
     * Who is held to "details after the crew" — the owner's call alone. The
     * ticked ids come from the browser, so only real, non-owner accounts of
     * this database are taken from them.
     */
    public function saveLockedStaff(): void
    {
        $this->authorizeAdmin();

        $actor = Auth::user();
        abort_unless($actor instanceof User && $actor->isSuperAdmin(), 403);

        if (! Schema::hasColumn('users', 'trip_details_locked')) {
            return;
        }

        $wanted = array_values(array_unique(array_map('intval', $this->lockedUserIds)));
        $switches = app(TripStaffSwitches::class);
        $locked = [];

        // The same switch as Settings → Users: set on every copy of the
        // account, so it holds in whichever database the person works.
        foreach ($this->lockableStaff() as $person) {
            $on = in_array((int) $person->id, $wanted, true);
            if ($on) {
                $locked[] = (int) $person->id;
            }
            if (($person->getAttribute('trip_details_locked') ?? false) !== $on) {
                $switches->set($person, TripStaffSwitches::LOCK, $on);
            }
        }

        $this->lockedUserIds = $locked;
        $this->staffSaved = true;
    }

    /**
     * Everyone in this database but the owners: a super admin always sees
     * everything ({@see User::seesTripDetailsOnlyWhenCrewed()}).
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, User>
     */
    private function lockableStaff(): \Illuminate\Database\Eloquent\Collection
    {
        return User::query()
            ->where(static fn ($q) => $q->where('is_super_admin', false)->orWhereNull('is_super_admin'))
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'trip_details_locked']);
    }

    public function updated(string $name): void
    {
        if ($name === 'lockedUserIds' || str_starts_with($name, 'lockedUserIds.')) {
            $this->staffSaved = false;

            return;
        }

        $this->saved = false;
    }

    public function render(): View
    {
        $actor = Auth::user();

        return view('limousine::crew-alert-settings', [
            'customerTemplate' => CrewNotices::TEMPLATE_CUSTOMER,
            'reminderTemplate' => CrewNotices::TEMPLATE_REMINDER,
            // Messages go out on THIS database's WhatsApp connection; without
            // one, switching on would send nothing and say nothing.
            'whatsappReady' => Schema::hasTable('whatsapp_configuration') && WhatsAppConfiguration::current()->isConfigured(),
            'staff' => Schema::hasColumn('users', 'trip_details_locked') ? $this->lockableStaff() : collect(),
            'mayLockStaff' => $actor instanceof User && $actor->isSuperAdmin(),
        ]);
    }
}
