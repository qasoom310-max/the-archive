<?php

declare(strict_types=1);

namespace App\Livewire\Pages;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Pos\Enums\OrderState;
use Modules\Pos\Models\PosOrder;
use Modules\Purchases\Enums\PurchaseState;
use Modules\Purchases\Models\Purchase;

/**
 * Daily Summary — a one-glance owner's P&L for a day: money IN (POS sales),
 * money OUT (confirmed purchases), and the Net (sales − purchases). Net is
 * shown red when negative so a loss day is obvious. A date picker scopes the
 * figures and a 7-day table gives the trend. Admin-only (financial data).
 *
 * "Net" here is **cash in vs cash out** for the day (sales total − purchase
 * total), not an accrual P&L — buying a month of stock in one day shows as a
 * minus on that day by design.
 */
#[Layout('components.layouts.app')]
#[Title('Daily Summary')]
final class DailySummary extends Component
{
    #[Url(except: '')]
    public string $date = '';

    public function mount(): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->isAdmin(), 403);

        if ($this->date === '') {
            $this->date = Carbon::today()->toDateString();
        }
    }

    /**
     * Sales / purchases / net for a single calendar day.
     *
     * @return array{sales: float, purchases: float, net: float}
     */
    private function dayTotals(string $date): array
    {
        $sales = 0.0;
        if (Schema::hasTable('pos_orders')) {
            $sales = (float) PosOrder::query()
                ->where('state', OrderState::Done->value)
                ->whereDate('ordered_at', $date)
                ->sum('total');
        }

        $purchases = 0.0;
        if (Schema::hasTable('purchases')) {
            $purchases = (float) Purchase::query()
                ->where('state', PurchaseState::Confirmed->value)
                ->whereDate('date', $date)
                ->sum('total');
        }

        $sales = round($sales, 2);
        $purchases = round($purchases, 2);

        return [
            'sales' => $sales,
            'purchases' => $purchases,
            'net' => round($sales - $purchases, 2),
        ];
    }

    public function render(): View
    {
        $selected = $this->date !== '' ? $this->date : Carbon::today()->toDateString();
        $base = Carbon::parse($selected);

        // 7-day trend ending on the selected day (most recent first).
        $history = [];
        for ($i = 0; $i < 7; $i++) {
            $day = $base->copy()->subDays($i);
            $history[] = [
                'date' => $day->toDateString(),
                'label' => $day->isoFormat('ddd, MMM D'),
                'totals' => $this->dayTotals($day->toDateString()),
            ];
        }

        return view('livewire.pages.daily-summary', [
            'today' => $this->dayTotals($selected),
            'dayLabel' => $base->isoFormat('dddd, MMMM D, YYYY'),
            'history' => $history,
        ]);
    }
}
