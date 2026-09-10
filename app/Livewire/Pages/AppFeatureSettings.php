<?php

declare(strict_types=1);

namespace App\Livewire\Pages;

use App\Erp\Activity\ActivityLogger;
use App\Erp\Business\Feature;
use App\Erp\Business\Features;
use App\Erp\Enums\ModuleState;
use App\Models\Ir\IrModule;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * An app's own "Settings" tab (`/app/{module}/settings`) — checkboxes that turn
 * that app's sub-features on/off for the active database (e.g. POS → Dine-in,
 * Recipes). The toggles are manual overrides that WIN over the business-type
 * preset (see {@see Features}), so an admin can force Dine-in on in a retail
 * shop or off in a café.
 *
 * Admin-gated (any admin), per the chosen policy. Only apps with sub-features
 * (a non-empty {@see Features::appFeatures()} entry) have this page; any other
 * slug 404s.
 */
#[Layout('components.layouts.app')]
#[Title('App settings')]
final class AppFeatureSettings extends Component
{
    public string $module = '';

    /** @var array<string, bool> feature value => enabled */
    public array $toggles = [];

    public bool $saved = false;

    public function mount(string $module): void
    {
        $user = $this->guardAdmin();
        abort_unless($user->mayAdministerApp($module), 403);

        $this->module = $module;

        $features = Features::appFeatures($module);
        abort_if($features === [], 404);

        // Only a real, installed application module reaches the form.
        $installed = IrModule::query()
            ->where('name', $module)
            ->where('application', true)
            ->where('state', ModuleState::Installed)
            ->exists();
        abort_unless($installed, 404);

        foreach ($features as $feature) {
            $this->toggles[$feature->value] = Features::enabled($feature);
        }
    }

    /** @return User the confirmed admin, so callers can re-check the app scope */
    private function guardAdmin(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->isAdmin(), 403);

        return $user;
    }

    public function save(): void
    {
        $user = $this->guardAdmin();
        abort_unless($user->mayAdministerApp($this->module), 403);

        $values = [];
        foreach (Features::appFeatures($this->module) as $feature) {
            $values[$feature->value] = (bool) ($this->toggles[$feature->value] ?? false);
        }

        Features::setOverrides($values);

        app(ActivityLogger::class)->log(
            'settings_updated',
            $this->module,
            __('Updated :app feature toggles', ['app' => $this->module]),
        );

        $this->saved = true;
    }

    public function updated(): void
    {
        $this->saved = false;
    }

    public function render(): View
    {
        /** @var list<Feature> $features */
        $features = Features::appFeatures($this->module);

        $key = 'module.' . $this->module;
        $label = __($key);
        if ($label === $key) {
            $module = IrModule::query()->where('name', $this->module)->first();
            $label = $module instanceof IrModule ? (string) $module->display_name : ucfirst($this->module);
        }

        return view('livewire.pages.app-feature-settings', [
            'features' => $features,
            'moduleLabel' => $label,
        ]);
    }
}
