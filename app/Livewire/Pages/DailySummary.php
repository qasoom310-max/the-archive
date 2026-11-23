<?php

declare(strict_types=1);

namespace App\Livewire\Pages;

use App\Erp\Business\Features;
use App\Erp\Settings\BusinessDay;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
 * (POS sales + Limousine and Rent A Car receipts, i.e. money actually received
 * that day; rental deposits are on the order, not receipts, so never count),
 * money OUT (confirmed purchases), and the Net Cash Flow
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
        abort_unless($user instanceof User && $user->seesOwnerReports(), 403);

        if ($this->date === '') {
            $this->date = BusinessDay::of();
        }
    }

    /**
     * Money in / purchases / net for a single business day.
     *
     * Shop sales are counted over the business day ({@see BusinessDay}): with
     * the day starting at 08:00, a sale at 2 AM belongs to the evening before.
     * Limousine and Rent A Car receipts and purchases carry a date and no time,
     * so they stay on their date.
     *
     * @return array{pos: float, limo: float, rental: float, sales: float, purchases: float, net: float}
     */
    private function dayTotals(string $date): array
    {
        $pos = 0.0;
        if (Schema::hasTable('pos_orders')) {
            $pos = (float) PosOrder::query()
                ->where('state', OrderState::Done->value)
                ->where('ordered_at', '>=', BusinessDay::window($date)[0]->toDateTimeString())
                ->where('ordered_at', '<', BusinessDay::window($date)[1]->toDateTimeString())
                ->sum('total');
        }

        $limo = $this->receipts('limousine', 'limo_receipts', $date);
        $rental = $this->receipts('rental', 'rental_receipts', $date);
        // Money taken on rental orders (the Advance), dated since 23 Nov 2026.
        if ($this->uses('rental', 'rental_order_payments')) {
            $rental = round($rental + \Modules\Rental\Models\RentalOrderPayment::moneyOn($date), 2);
        }

        $purchases = 0.0;
        if (Schema::hasTable('purchases')) {
            $purchases = (float) Purchase::query()
                ->where('state', PurchaseState::Confirmed->value)
                ->whereDate('date', $date)
                ->sum('total');
        }

        $pos = round($pos, 2);
        $sales = round($pos + $limo + $rental, 2);
        $purchases = round($purchases, 2);

        return [
            'pos' => $pos,
            'limo' => $limo,
            'rental' => $rental,
            'sales' => $sales,
            'purchases' => $purchases,
            'net' => round($sales - $purchases, 2),
        ];
    }

    /** Money received in one car section on a date (0 where the section is not used). */
    private function receipts(string $module, string $table, string $date): float
    {
        if (! $this->uses($module, $table)) {
            return 0.0;
        }

        return round((float) DB::table($table)->whereDate('date', $date)->sum('amount'), 2);
    }

    /** @var array<string, bool> */
    private array $uses = [];

    /** Whether this database runs that section (its table exists and its app is on). */
    private function uses(string $module, string $table): bool
    {
        return $this->uses[$table] ??= Schema::hasTable($table) && Features::moduleAllowed($module);
    }

    public function render(): View
    {
        $selected = $this->date !== '' ? $this->date : BusinessDay::of();
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
            'dayStartsAt' => BusinessDay::startHour(),
            'sources' => [
                'pos' => Schema::hasTable('pos_orders') && Features::moduleAllowed('pos'),
                'limo' => $this->uses('limousine', 'limo_receipts'),
                'rental' => $this->uses('rental', 'rental_receipts'),
            ],
            'history' => $history,
        ]);
    }
}
