<?php

declare(strict_types=1);

namespace App\Livewire\Pages;

use App\Erp\Enums\ModuleState;
use App\Models\Demo\DemoTicket;
use App\Models\Ir\IrModel;
use App\Models\Ir\IrModule;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('Dashboard')]
final class Dashboard extends Component
{
    public function render(): View
    {
        return view('livewire.pages.dashboard', [
            'ticket' => DemoTicket::query()->first(),
            'appCount' => IrModule::query()
                ->where('application', true)
                ->where('state', ModuleState::Installed)
                ->count(),
            'moduleCount' => IrModule::query()
                ->where('state', ModuleState::Installed)
                ->count(),
            'modelCount' => IrModel::query()->count(),
        ]);
    }
}
