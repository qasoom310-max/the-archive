<?php

declare(strict_types=1);

namespace App\Livewire\Pages;

use App\Erp\Business\Features;
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
use Modules\Pos\Services\PosStockReportData;

#[Layout('components.layouts.app')]
#[Title('Dashboard')]
final class Dashboard extends Component
{
    private function isAdmin(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->isAdmin();
    }

    private function isSuperAdmin(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->isSuperAdmin();
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
        $inventoryValue = null;

        if ($isAdmin && $this->posReady()) {
            $report = app(DailyReport::class);
            [$start, $end] = $report->currentWindow();
            $dailySales = $report->sales($start, $end);
            $stockSummary = $report->stock();
            $periodLabel = $start->isoFormat('MMM D, h:mm A') . ' – ' . $end->isoFormat('MMM D, h:mm A');
            // Total money tied up in stock (on-hand × cost), active items only.
            $inventoryValue = app(PosStockReportData::class)->summary(false)['value'];
        }

        // Quick-launch buttons: every installed application module the active
        // database's business type allows (same filter as the top app bar), so
        // a rental+limo store lands on Rent A Car / Limousine, a café on POS,
        // etc. — straight from the dashboard.
        $apps = IrModule::query()
            ->where('application', true)
            ->where('state', ModuleState::Installed)
            ->orderBy('sequence')
            ->get()
            ->filter(fn (IrModule $app): bool => Features::moduleAllowed($app->name))
            ->values();

        return view('livewire.pages.dashboard', [
            'ticket' => DemoTicket::query()->first(),
            'apps' => $apps,
            'appCount' => IrModule::query()
                ->where('application', true)
                ->where('state', ModuleState::Installed)
                ->count(),
            'moduleCount' => IrModule::query()
                ->where('state', ModuleState::Installed)
                ->count(),
            'modelCount' => IrModel::query()->count(),
            'isAdmin' => $isAdmin,
            'isSuperAdmin' => $this->isSuperAdmin(),
            'dailySales' => $dailySales,
            'stockSummary' => $stockSummary,
            'reportPeriod' => $periodLabel,
            'inventoryValue' => $inventoryValue,
        ]);
    }
}
