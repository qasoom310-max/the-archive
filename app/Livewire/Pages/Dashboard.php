<?php

declare(strict_types=1);

namespace App\Livewire\Pages;

use App\Erp\Enums\ModuleState;
use App\Models\Demo\DemoTicket;
use App\Models\Ir\IrModel;
use App\Models\Ir\IrModule;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Pos\Services\DailyReport;

#[Layout('components.layouts.app')]
#[Title('Dashboard')]
final class Dashboard extends Component
{
    private function isAdmin(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->isAdmin();
    }

    /**
     * Whether the POS data the report cards read from exists yet.
     */
    private function posReady(): bool
    {
        return Schema::hasTable('pos_orders') && Schema::hasTable('pos_products');
    }

    public function render(): View
    {
        $isAdmin = $this->isAdmin();

        $dailySales = null;
        $stockSummary = null;
        $periodLabel = null;

        if ($isAdmin && $this->posReady()) {
            $report = app(DailyReport::class);
            [$start, $end] = $report->currentWindow();
            $dailySales = $report->sales($start, $end);
            $stockSummary = $report->stock();
            $periodLabel = $start->isoFormat('MMM D, h:mm A') . ' – ' . $end->isoFormat('MMM D, h:mm A');
        }

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
            'isAdmin' => $isAdmin,
            'dailySales' => $dailySales,
            'stockSummary' => $stockSummary,
            'reportPeriod' => $periodLabel,
        ]);
    }
}
