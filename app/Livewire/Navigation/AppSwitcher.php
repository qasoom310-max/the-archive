<?php

declare(strict_types=1);

namespace App\Livewire\Navigation;

use App\Erp\Enums\ModuleState;
use App\Models\Ir\IrModule;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * App bar: an always-visible inline row of every installed
 * *application* module, read live from the ir_module registry, rendered
 * directly in the topbar (replaced the old 9-square grid dropdown). The
 * app whose screen is currently open is highlighted via $activeModule.
 */
final class AppSwitcher extends Component
{
    /** Slug of the module currently being viewed (/app/{module}/…), or null. */
    public ?string $activeModule = null;

    public function render(): View
    {
        /** @var Collection<int, IrModule> $apps */
        $apps = IrModule::query()
            ->where('application', true)
            ->where('state', ModuleState::Installed)
            ->orderBy('sequence')
            ->get();

        return view('livewire.navigation.app-switcher', [
            'apps' => $apps,
        ]);
    }
}
