<?php

declare(strict_types=1);

namespace Modules\Rental\Livewire;

use App\Erp\Navigation\ModuleMenu;
use App\Models\Ir\IrModule;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Rental\Models\Branch;
use Modules\Rental\Models\Vehicle;

/**
 * Car-rental operations dashboard: live fleet KPIs (total / available /
 * rented / under maintenance) plus per-branch availability, and the Masters
 * tiles for one-hop navigation. Order/revenue cards arrive with the Orders
 * phase — this view reports what the fleet data already knows.
 */
#[Layout('components.layouts.app')]
#[Title('Rent A Car')]
final class RentalHome extends Component
{
    public function render(): View
    {
        // One grouped query for the whole status breakdown (active fleet only).
        $byStatus = Vehicle::query()
            ->where('active', true)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $available = (int) $byStatus->get(Vehicle::STATUS_AVAILABLE, 0);
        $rented = (int) $byStatus->get(Vehicle::STATUS_RENTED, 0);
        $maintenance = (int) $byStatus->get(Vehicle::STATUS_MAINTENANCE, 0);
        $reserved = (int) $byStatus->get(Vehicle::STATUS_RESERVED, 0);
        $total = (int) $byStatus->sum();

        // Per-branch availability (matches the Branch 1/2/3 cards).
        $branches = Branch::query()
            ->where('active', true)
            ->orderBy('name')
            ->withCount([
                'vehicles as total_count' => fn ($q) => $q->where('active', true),
                'vehicles as available_count' => fn ($q) => $q
                    ->where('active', true)
                    ->where('status', Vehicle::STATUS_AVAILABLE),
            ])
            ->get();

        // Masters tiles — ACL-filtered, same source as the app-bar dropdown.
        $module = IrModule::query()->where('name', 'rental')->first();
        $tiles = $module !== null ? app(ModuleMenu::class)->items($module, Auth::user()) : [];

        return view('rental::home', [
            'total' => $total,
            'available' => $available,
            'rented' => $rented,
            'maintenance' => $maintenance,
            'reserved' => $reserved,
            'branches' => $branches,
            'tiles' => $tiles,
        ]);
    }
}
