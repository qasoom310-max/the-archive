<?php

declare(strict_types=1);

namespace App\Livewire\Pages;

use App\Erp\Enums\ModuleState;
use App\Erp\Navigation\ModuleMenu;
use App\Models\Ir\IrModule;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Generic landing page for an installed application module: an Odoo-style
 * dashboard of clickable tiles, one per registered model the user may Read
 * (built by {@see ModuleMenu}, the same source the contextual Sidebar uses).
 */
final class ModuleHome extends Component
{
    public string $moduleName;

    public function mount(string $module): void
    {
        $exists = IrModule::query()
            ->where('name', $module)
            ->where('state', ModuleState::Installed)
            ->exists();

        abort_unless($exists, 404);

        $this->moduleName = $module;
    }

    public function render(): View
    {
        $module = IrModule::query()->where('name', $this->moduleName)->firstOrFail();

        $tiles = app(ModuleMenu::class)->items($module, Auth::user());

        return view('livewire.pages.module-home', [
            'module' => $module,
            'tiles' => $tiles,
        ])->layout('components.layouts.app', ['title' => $module->display_name]);
    }
}
