<?php

declare(strict_types=1);

namespace App\Livewire\Navigation;

use App\Erp\Enums\ModuleState;
use App\Models\Ir\IrModule;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * Odoo-style app-switcher: a grid dropdown of every installed
 * *application* module, read live from the ir_module registry.
 */
final class AppSwitcher extends Component
{
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
