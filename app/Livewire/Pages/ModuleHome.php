<?php

declare(strict_types=1);

namespace App\Livewire\Pages;

use App\Erp\Enums\ModuleState;
use App\Models\Ir\IrModel;
use App\Models\Ir\IrModule;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Generic landing page for an installed application module: lists its
 * registered models. Phase 4 replaces these with real List/Kanban views.
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

        $models = IrModel::query()
            ->where('module', $this->moduleName)
            ->orderBy('name')
            ->get();

        return view('livewire.pages.module-home', [
            'module' => $module,
            'models' => $models,
        ])->layout('components.layouts.app', ['title' => $module->display_name]);
    }
}
