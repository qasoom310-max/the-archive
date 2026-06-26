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
use Modules\Rental\Models\RentalOrder;
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

        // Order KPIs (now that orders exist).
        $activeOrders = RentalOrder::query()->where('state', RentalOrder::STATE_ACTIVE)->count();
        $draftOrders = RentalOrder::query()->where('state', RentalOrder::STATE_DRAFT)->count();
        $unpaidOrders = RentalOrder::query()
            ->where('payment_status', RentalOrder::PAYMENT_UNPAID)
            ->whereIn('state', [RentalOrder::STATE_ACTIVE, RentalOrder::STATE_CLOSED])
            ->count();
        $returnsDue = RentalOrder::query()
            ->where('state', RentalOrder::STATE_ACTIVE)
            ->whereDate('end_date', '<=', now())
            ->count();
        $revenue = (float) RentalOrder::query()
            ->where('payment_status', RentalOrder::PAYMENT_PAID)
            ->sum('total');

        // Cars whose registration / insurance is missing, expired, or expiring
        // within the reminder window — surfaced as a "renew soon" box.
        $horizon = now()->addDays(Vehicle::RENEWAL_REMINDER_DAYS)->toDateString();
        $renewalAlerts = Vehicle::query()
            ->where('active', true)
            ->where(function ($q) use ($horizon): void {
                $q->whereNull('registration_expiry')
                    ->orWhereNull('insurance_expiry')
                    ->orWhereDate('registration_expiry', '<=', $horizon)
                    ->orWhereDate('insurance_expiry', '<=', $horizon);
            })
            ->get(['id', 'name', 'plate_no', 'color', 'registration_expiry', 'insurance_expiry'])
            // Most urgent first: a missing/expired paper (no next date) sorts
            // ahead of the soonest upcoming expiry.
            ->sortBy(fn (Vehicle $v): string => $v->nextDocExpiry()?->toDateString() ?? '0001-01-01')
            ->values();

        // Masters tiles — ACL-filtered, same source as the app-bar dropdown.
        $module = IrModule::query()->where('name', 'rental')->first();
        $tiles = $module !== null ? app(ModuleMenu::class)->items($module, Auth::user()) : [];

        return view('rental::home', [
            'renewalAlerts' => $renewalAlerts,
            'total' => $total,
            'available' => $available,
            'rented' => $rented,
            'maintenance' => $maintenance,
            'reserved' => $reserved,
            'branches' => $branches,
            'activeOrders' => $activeOrders,
            'draftOrders' => $draftOrders,
            'unpaidOrders' => $unpaidOrders,
            'returnsDue' => $returnsDue,
            'revenue' => $revenue,
            'tiles' => $tiles,
        ]);
    }
}
