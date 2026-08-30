<?php

declare(strict_types=1);

namespace Modules\Limousine\Livewire;

use App\Erp\Navigation\ModuleMenu;
use App\Erp\Security\Permission;
use App\Livewire\Concerns\GuardsModelAccess;
use App\Models\Ir\IrModule;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoLeg;

/**
 * Limousine dashboard: the bookings queue, today's and tomorrow's trips,
 * completed trips, unpaid bookings and collected revenue.
 */
#[Layout('components.layouts.app')]
#[Title('Limousine')]
final class LimoHome extends Component
{
    use GuardsModelAccess;

    protected function accessModelKey(): string
    {
        return 'limousine.booking';
    }

    public function mount(): void
    {
        $this->guardAccess(Permission::Read);
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

        // Trips running that day — legs are dated individually, so a two-day job
        // counts on each of its days rather than only its booking date.
        $byDay = fn (string $date): int => LimoLeg::query()
            ->whereMorphedTo('legable', LimoBooking::class)
            ->whereDate('start_at', $date)
            ->where('status', '!=', LimoLeg::STATUS_CANCELLED)
            ->count();

        $module = IrModule::query()->where('name', 'limousine')->first();
        $tiles = $module !== null ? app(ModuleMenu::class)->items($module, Auth::user()) : [];

        return view('limousine::home', [
            'queue' => $queue,
            'confirmed' => $confirmed,
            'active' => $active,
            'completed' => $completed,
            'unpaid' => $unpaid,
            'revenue' => $revenue,
            'yesterdayCount' => $byDay($yesterday),
            'todayCount' => $byDay($today),
            'tomorrowCount' => $byDay($tomorrow),
            'tiles' => $tiles,
        ]);
    }
}
