<?php

declare(strict_types=1);

namespace App\Livewire\Pages;

use App\Erp\Business\Feature;
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
    use \App\Livewire\Concerns\HasAdminCheck;

    /**
     * Apps pinned to the top of the "Your apps" launcher as a 2-up featured
     * row (Rent A Car on the left, Limousine on the right). Order here is the
     * left→right order. Anything not listed flows into the 3-up grid below.
     *
     * @var list<string>
     */
    private const FEATURED_APPS = ['rental', 'limousine'];

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

        // The Daily sale + Daily stock cards are POS-derived and admins can
        // hide them from POS → Settings (Features::DailyReportCards). Skipping
        // the computation leaves the values null, which the Blade reads as
        // "don't render the cards".
        if ($isAdmin && $this->posReady() && Features::enabled(Feature::DailyReportCards)) {
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

        // Feature the transport apps (Rent A Car + Limousine) as a prominent
        // 2-up top row; everything else flows 3-up beneath. On a non-transport
        // business `featuredApps` is empty and all apps fall to `otherApps`.
        $featuredApps = collect(self::FEATURED_APPS)
            ->map(fn (string $name): ?IrModule => $apps->firstWhere('name', $name))
            ->filter()
            ->values();
        $otherApps = $apps->reject(
            fn (IrModule $app): bool => in_array($app->name, self::FEATURED_APPS, true),
        )->values();

        return view('livewire.pages.dashboard', [
            'ticket' => DemoTicket::query()->first(),
            'apps' => $apps,
            'featuredApps' => $featuredApps,
            'otherApps' => $otherApps,
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
