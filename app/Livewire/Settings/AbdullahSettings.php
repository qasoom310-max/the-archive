<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Erp\Abdullah\Abdullah;
use App\Erp\Activity\ActivityLogger;
use App\Erp\Settings\Setting;
use App\Models\AbdullahMessage;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Settings → Abdullah (owner only): switch him on or off, his monthly limit,
 * where trip reminders go and how often, each person's language, and
 * everything he has said, so the owner can see how he speaks to the staff.
 */
#[Layout('components.layouts.app')]
#[Title('Abdullah')]
final class AbdullahSettings extends Component
{
    public bool $enabled = true;

    public string $monthlyLimit = '';

    public string $opsEmail = '';

    public string $tripEvery = '';

    public string $tripMax = '';

    public function mount(Abdullah $abdullah): void
    {
        $this->authorizeOwner();

        $this->enabled = (bool) Setting::get('abdullah.enabled', true);
        $this->monthlyLimit = (string) $abdullah->monthlyLimit();
        $this->opsEmail = $abdullah->opsEmail();
        $this->tripEvery = (string) $abdullah->tripEveryMinutes();
        $this->tripMax = (string) $abdullah->tripMax();
    }

    private function authorizeOwner(): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->isSuperAdmin(), 403, __('Only the owner can manage Abdullah.'));
    }

    public function save(ActivityLogger $activity): void
    {
        $this->authorizeOwner();

        $this->validate([
            'monthlyLimit' => ['required', 'integer', 'min:0', 'max:100000'],
            'opsEmail' => ['nullable', 'email', 'max:190'],
            'tripEvery' => ['required', 'integer', 'min:5', 'max:240'],
            'tripMax' => ['required', 'integer', 'min:1', 'max:50'],
        ]);

        Setting::setMany([
            'abdullah.enabled' => $this->enabled,
            'abdullah.monthly_limit' => (int) $this->monthlyLimit,
            'abdullah.ops_email' => trim($this->opsEmail),
            'abdullah.trip_every_minutes' => (int) $this->tripEvery,
            'abdullah.trip_max' => (int) $this->tripMax,
        ]);

        $activity->log('settings_updated', __('Abdullah'), __('Abdullah settings saved'));
        session()->flash('toast', __('Saved.'));
    }

    public function setLanguage(int $userId, string $code): void
    {
        $this->authorizeOwner();
        if (! array_key_exists($code, Abdullah::LANGUAGES)) {
            return;
        }

        User::query()->whereKey($userId)->update(['abdullah_language' => $code]);
    }

    public function toggleOff(int $userId): void
    {
        $this->authorizeOwner();

        $user = User::query()->find($userId);
        if ($user !== null) {
            $user->forceFill(['abdullah_off' => ! ((bool) $user->getAttribute('abdullah_off'))])->save();
        }
    }

    public function render(Abdullah $abdullah): View
    {
        $ready = Abdullah::ready();

        return view('livewire.settings.abdullah-settings', [
            'ready' => $ready,
            'users' => User::query()->orderBy('name')->get(),
            'hasTripColumn' => Schema::hasColumn('users', 'trip_reminders'),
            'languages' => Abdullah::LANGUAGES,
            'abdullah' => $abdullah,
            'hasKey' => $abdullah->apiKey() !== '',
            'used' => $ready ? $abdullah->usedThisMonth() : 0,
            'log' => $ready ? AbdullahMessage::query()->with('user:id,name')->whereNotNull('body')->latest('id')->limit(80)->get() : collect(),
        ]);
    }
}
