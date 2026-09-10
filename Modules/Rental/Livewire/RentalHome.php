<?php

declare(strict_types=1);

namespace Modules\Rental\Livewire;

use App\Erp\Customers\TopCustomers;
use App\Erp\Navigation\ModuleMenu;
use App\Erp\Security\Permission;
use App\Erp\Targets\RevenueSchedule;
use App\Erp\Targets\RevenueTargets;
use App\Livewire\Concerns\EditsRevenueTargets;
use App\Livewire\Concerns\GuardsModelAccess;
use App\Models\Ir\IrModule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Rental\Models\Branch;
use Modules\Rental\Models\RentalMaintenance;
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
    use EditsRevenueTargets;
    use GuardsModelAccess;

    protected function targetsApp(): string
    {
        return 'rental';
    }

    protected function accessModelKey(): string
    {
        return 'rental.order';
    }

    public function mount(): void
    {
        $this->guardAccess(Permission::Read);
    }

    public function render(): View
    {
        // One grouped query for the whole status breakdown — OWNED active fleet
        // only (cars rented in from outside are excluded from the fleet KPIs).
        $byStatus = Vehicle::query()
            ->where('active', true)
            ->owned()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $available = (int) $byStatus->get(Vehicle::STATUS_AVAILABLE, 0);
        $rented = (int) $byStatus->get(Vehicle::STATUS_RENTED, 0);
        $maintenance = (int) $byStatus->get(Vehicle::STATUS_MAINTENANCE, 0);
        $reserved = (int) $byStatus->get(Vehicle::STATUS_RESERVED, 0);
        $total = (int) $byStatus->sum();

        // Cars rented in from outside — shown as context, not part of the fleet.
        $outsideCount = (int) Vehicle::query()->where('active', true)->where('is_outside', true)->count();

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
        // Orders still owing money (unpaid or part-paid) on a live/closed order —
        // both the count and the outstanding balance feed the revenue box.
        $owing = RentalOrder::query()
            ->whereIn('payment_status', [RentalOrder::PAYMENT_UNPAID, RentalOrder::PAYMENT_PARTIAL])
            ->whereIn('state', [RentalOrder::STATE_ACTIVE, RentalOrder::STATE_CLOSED]);
        $unpaidOrders = (clone $owing)->count();
        $unpaidOutstanding = (float) (clone $owing)->sum('balance');
        $returnsDue = RentalOrder::query()
            ->where('state', RentalOrder::STATE_ACTIVE)
            ->whereDate('end_date', '<=', now())
            ->count();
        // Revenue is net of what we pay outside vendors (markup, not gross).
        $revenue = (float) RentalOrder::query()
            ->where('payment_status', RentalOrder::PAYMENT_PAID)
            ->selectRaw('COALESCE(SUM(total - outside_cost), 0) as net')
            ->value('net');

        // Cars whose registration / insurance is missing, expired, or expiring
        // within the reminder window — surfaced as a "renew soon" box.
        // Owned cars only — outside (rented-in) cars' papers aren't ours to renew.
        $horizon = now()->addDays(Vehicle::RENEWAL_REMINDER_DAYS)->toDateString();
        $renewalAlerts = Vehicle::query()
            ->owned()
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

        // Deposits ready to refund — held, the 14-day hold has elapsed, and not
        // yet settled. Only an accountant / super-admin needs this worklist, so
        // it's gated and hidden from everyone else.
        $user = Auth::user();
        $canSeeRefunds = $user instanceof User && $user->canConfirmPayments();
        $depositsToRefund = $canSeeRefunds
            ? RentalOrder::query()
                ->where('deposit', '>', 0)
                ->where('deposit_status', RentalOrder::DEPOSIT_HELD)
                ->whereNotNull('returned_at')
                ->whereDate('returned_at', '<=', now()->subDays(RentalOrder::DEPOSIT_HOLD_DAYS)->toDateString())
                ->with('customer:id,name', 'vehicle:id,name,plate_no,color')
                ->orderBy('returned_at')
                ->get()
            : collect();

        // Maintenance work orders awaiting a manager's approval — the manager's
        // queue, most urgent (Critical) first. Only a fleet manager sees it.
        $canApprove = $user instanceof User && $user->canApproveMaintenance();
        $rank = ['critical' => 3, 'high' => 2, 'normal' => 1, 'low' => 0];
        $pendingWorkOrders = $canApprove
            ? RentalMaintenance::query()
                ->where('status', RentalMaintenance::STATUS_PENDING)
                ->with('vehicle:id,name,plate_no,color', 'requestedBy:id,name')
                ->get()
                ->sortByDesc(fn (RentalMaintenance $m): int => $rank[$m->priority] ?? 1)
                ->values()
            : collect();

        // The current user's OWN maintenance requests + their outcome (the
        // lightweight "your request was approved / declined" notification).
        $myRequests = $user instanceof User
            ? RentalMaintenance::query()
                ->where('requested_by_user_id', $user->getKey())
                ->whereIn('status', [RentalMaintenance::STATUS_PENDING, RentalMaintenance::STATUS_APPROVED, RentalMaintenance::STATUS_DECLINED])
                ->whereDate('created_at', '>=', now()->subDays(30)->toDateString())
                ->with('vehicle:id,name,plate_no,color', 'approvedBy:id,name')
                ->orderByDesc('id')
                ->get()
            : collect();

        // Masters tiles — ACL-filtered, same source as the app-bar dropdown.
        $module = IrModule::query()->where('name', 'rental')->first();
        $tiles = $module !== null ? app(ModuleMenu::class)->items($module, Auth::user()) : [];

        // The revenue figure IS the whole income, so it and the targets
        // measured against it are the owner's alone. Skipped entirely for
        // everyone else rather than fetched and hidden in the view.
        $isSuperAdmin = $this->viewerIsSuperAdmin();
        $targets = $isSuperAdmin ? app(RevenueTargets::class)->progress('rental') : [];

        // The schedules and the call sheet are the owner's too, and they
        // cost four more queries, so nobody else pays for them.
        $now = CarbonImmutable::now();
        $schedule = app(RevenueSchedule::class);
        $schedules = $isSuperAdmin ? [
            'month' => $schedule->forWindow('rental', $now->startOfMonth(), $now->endOfMonth()),
            // A car's target is monthly, so the year is judged against twelve of them.
            'year' => $schedule->forWindow('rental', $now->startOfYear(), $now->endOfYear(), 12.0),
        ] : [];
        $topCustomers = $isSuperAdmin ? app(TopCustomers::class)->forApp('rental', $now) : [];

        return view('rental::home', [
            'isSuperAdmin' => $isSuperAdmin,
            'targets' => $targets,
            'schedules' => $schedules,
            'topCustomers' => $topCustomers,
            'pendingWorkOrders' => $pendingWorkOrders,
            'canApproveMaintenance' => $canApprove,
            'myRequests' => $myRequests,
            'depositsToRefund' => $depositsToRefund,
            'canSeeRefunds' => $canSeeRefunds,
            'renewalAlerts' => $renewalAlerts,
            'total' => $total,
            'outsideCount' => $outsideCount,
            'available' => $available,
            'rented' => $rented,
            'maintenance' => $maintenance,
            'reserved' => $reserved,
            'branches' => $branches,
            'activeOrders' => $activeOrders,
            'draftOrders' => $draftOrders,
            'unpaidOrders' => $unpaidOrders,
            'unpaidOutstanding' => $unpaidOutstanding,
            'returnsDue' => $returnsDue,
            'revenue' => $revenue,
            'tiles' => $tiles,
        ]);
    }
}
