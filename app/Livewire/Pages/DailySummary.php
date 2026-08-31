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
 * Daily Summary — a one-glance owner's CASH-FLOW view for a day: money IN
 * (POS sales), money OUT (confirmed purchases), and the Net Cash Flow
 * (sales − purchases). Net is shown red when negative so a cash-out day is
 * obvious. A date picker scopes the figures and a 7-day table gives the trend.
 * Admin-only (financial data).
 *
 * This is deliberately **cash in vs cash out**, NOT accounting profit: a
 * purchase converts cash into inventory (an asset), it isn't an expense until
 * the goods are sold (COGS). So buying a month of stock in one day shows as a
 * minus here by design — that's cash flow, not a loss.
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
                'label' => $day->isoFormat('ddd, DD-MMM'),
                'totals' => $this->dayTotals($day->toDateString()),
            ];
        }

        return view('livewire.pages.daily-summary', [
            'today' => $this->dayTotals($selected),
            'dayLabel' => $base->isoFormat('dddd, D MMMM YYYY'),
            'history' => $history,
        ]);
    }
}
