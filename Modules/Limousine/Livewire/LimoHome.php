<?php

declare(strict_types=1);

namespace Modules\Limousine\Livewire;

use App\Erp\Activity\ActivityLogger;
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
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Services\TripCancellation;

/**
 * Limousine dashboard: the bookings queue, today's and tomorrow's trips,
 * completed trips, unpaid bookings and collected revenue.
 */
#[Layout('components.layouts.app')]
#[Title('Limousine')]
final class LimoHome extends Component
{
    use EditsRevenueTargets;
    use GuardsModelAccess;

    protected function targetsApp(): string
    {
        return 'limousine';
    }

    protected function accessModelKey(): string
    {
        return 'limousine.booking';
    }

    public function mount(): void
    {
        $this->guardAccess(Permission::Read);
    }

    /**
     * Switch the cancellation coupon rule on or off for this database. The
     * owner and the Supervisor accountant only — the same people trusted to
     * confirm money was received. Re-checked here, not just hidden in the view.
     */
    public function toggleCouponRule(): void
    {
        abort_unless($this->viewerManagesCouponRule(), 403);

        $rule = app(TripCancellation::class);
        $on = ! $rule->couponRuleOn();
        $rule->setCouponRule($on);

        app(ActivityLogger::class)->log(
            'settings_updated',
            __('Coupon rule'),
            $on ? __('Switched on') : __('Switched off'),
        );
    }

    private function viewerManagesCouponRule(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->canConfirmPayments();
    }

    public function render(): View
    {
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();
        $tomorrow = now()->addDay()->toDateString();

        // Counted over LEGS, because a leg IS the trip: it is what the queue
        // lists, what its tabs count, and what these cards link to.
        //
        // Counting bookings made the dashboard contradict the queue. A booking
        // takes the status of its LEAST progressed live leg (see
        // LimoBooking::syncStatusFromLegs), so a job with one leg still queued
        // and another already running reads "queue" — the card showed 0 Active
        // while the Active tab it links to listed a running trip. Now a card's
        // number is exactly the number of rows clicking it gives.
        $legsWith = fn (string $status): int => LimoLeg::query()
            ->whereMorphedTo('legable', LimoBooking::class)
            ->where('status', $status)
            ->count();

        $queue = $legsWith(LimoLeg::STATUS_QUEUE);
        // Confirmed is a status the queue lists and nothing else counted: a trip
        // agreed with the customer but not yet running belonged to no card, so
        // the dashboard read zero while the queue had work on it.
        $confirmed = $legsWith(LimoLeg::STATUS_CONFIRMED);
        $active = $legsWith(LimoLeg::STATUS_ACTIVE);
        $completed = $legsWith(LimoLeg::STATUS_COMPLETED);

        // Money stays per BOOKING: the customer settles the whole job, not each
        // leg, so payment lives on the booking.
        $unpaid = LimoBooking::query()
            ->where('payment_status', LimoBooking::PAYMENT_UNPAID)
            ->whereNotIn('status', [LimoBooking::STATUS_CANCELLED])
            ->count();
        $revenue = (float) LimoBooking::query()->where('payment_status', LimoBooking::PAYMENT_PAID)->sum('fare');

        // Bookings on that day — legs are dated individually, so a two-day job
        // counts on each of its days rather than only its booking date.
        //
        // Each card links to the queue filtered to that day, so it counts what
        // that list will SHOW. A card whose number disagrees with the page it
        // opens is the bug the KPI cards above were already fixed for once —
        // which is why cancelled trips are dropped from BOTH: a trip that was
        // called off is not work for that day.
        $byDay = fn (string $date): int => LimoLeg::query()
            ->whereMorphedTo('legable', LimoBooking::class)
            ->whereDate('start_at', $date)
            // Nullable column: a bare `!=` would drop a leg with no status
            // at all, since SQL compares nothing to NULL successfully.
            ->where(fn ($q) => $q->where('status', '!=', LimoLeg::STATUS_CANCELLED)->orWhereNull('status'))
            ->count();

        $module = IrModule::query()->where('name', 'limousine')->first();
        $tiles = $module !== null ? app(ModuleMenu::class)->items($module, Auth::user()) : [];

        // The revenue figure IS the whole income, so it and the targets
        // measured against it are the owner's alone. Skipped entirely for
        // everyone else rather than fetched and hidden in the view.
        $isSuperAdmin = $this->viewerIsSuperAdmin();
        $targets = $isSuperAdmin ? app(RevenueTargets::class)->progress('limousine') : [];

        // The schedules and the call sheet are the owner's too, and they
        // cost four more queries, so nobody else pays for them.
        $now = CarbonImmutable::now();
        $schedule = app(RevenueSchedule::class);
        $schedules = $isSuperAdmin ? [
            'month' => $schedule->forWindow('limousine', $now->startOfMonth(), $now->endOfMonth()),
            // A car's target is monthly, so the year is judged against twelve of them.
            'year' => $schedule->forWindow('limousine', $now->startOfYear(), $now->endOfYear(), 12.0),
        ] : [];
        $topCustomers = $isSuperAdmin ? app(TopCustomers::class)->forApp('limousine', $now) : [];

        return view('limousine::home', [
            'isSuperAdmin' => $isSuperAdmin,
            'canManageCouponRule' => $this->viewerManagesCouponRule(),
            'couponRuleOn' => app(TripCancellation::class)->couponRuleOn(),
            'targets' => $targets,
            'schedules' => $schedules,
            'topCustomers' => $topCustomers,
            'queue' => $queue,
            'confirmed' => $confirmed,
            'active' => $active,
            'completed' => $completed,
            'unpaid' => $unpaid,
            'revenue' => $revenue,
            'yesterdayCount' => $byDay($yesterday),
            'todayCount' => $byDay($today),
            'tomorrowCount' => $byDay($tomorrow),
            // The dates themselves, so each card can open its own day.
            'yesterdayDate' => $yesterday,
            'todayDate' => $today,
            'tomorrowDate' => $tomorrow,
            'tiles' => $tiles,
        ]);
    }
}
