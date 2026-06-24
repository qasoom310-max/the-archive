<?php

declare(strict_types=1);

namespace Modules\Limousine\Livewire;

use App\Erp\Navigation\ModuleMenu;
use App\Models\Ir\IrModule;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Limousine\Models\LimoBooking;

/**
 * Limousine dashboard: the bookings queue, today's and tomorrow's trips,
 * completed trips, unpaid bookings and collected revenue.
 */
#[Layout('components.layouts.app')]
#[Title('Limousine')]
final class LimoHome extends Component
{
    public function render(): View
    {
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();
        $tomorrow = now()->addDay()->toDateString();

        $queue = LimoBooking::query()->where('status', LimoBooking::STATUS_QUEUE)->count();
        $active = LimoBooking::query()->whereIn('status', [LimoBooking::STATUS_CONFIRMED, LimoBooking::STATUS_ACTIVE])->count();
        $completed = LimoBooking::query()->where('status', LimoBooking::STATUS_COMPLETED)->count();
        $unpaid = LimoBooking::query()
            ->where('payment_status', LimoBooking::PAYMENT_UNPAID)
            ->whereNotIn('status', [LimoBooking::STATUS_CANCELLED])
            ->count();
        $revenue = (float) LimoBooking::query()->where('payment_status', LimoBooking::PAYMENT_PAID)->sum('fare');

        $byDay = fn (string $date): int => LimoBooking::query()
            ->whereDate('pickup_at', $date)
            ->whereNotIn('status', [LimoBooking::STATUS_CANCELLED])
            ->count();

        $module = IrModule::query()->where('name', 'limousine')->first();
        $tiles = $module !== null ? app(ModuleMenu::class)->items($module, Auth::user()) : [];

        return view('limousine::home', [
            'queue' => $queue,
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
