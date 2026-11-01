<?php

declare(strict_types=1);

namespace Modules\Limousine\Livewire;

use App\Erp\Security\Permission;
use App\Livewire\Concerns\GuardsModelAccess;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoExpense;
use Modules\Limousine\Models\LimoInvoice;
use Modules\Limousine\Models\LimoReceipt;

/**
 * Limousine reports: summary / bookings / customers over a shared pick-up date
 * range. The summary nets collected revenue against expenses.
 */
#[Layout('components.layouts.app')]
#[Title('Reports')]
final class Reports extends Component
{
    use GuardsModelAccess;

    /** summary | bookings | customers */
    #[Url]
    public string $tab = 'summary';

    protected function accessModelKey(): string
    {
        return 'limousine.booking';
    }

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public function mount(): void
    {
        $this->guardAccess(Permission::Read);

        if ($this->from === '') {
            $this->from = now()->startOfMonth()->format('Y-m-d');
        }
        if ($this->to === '') {
            $this->to = now()->endOfMonth()->format('Y-m-d');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function summaryData(): array
    {
        $bookings = LimoBooking::query()->whereBetween('pickup_at', [$this->from . ' 00:00:00', $this->to . ' 23:59:59']);

        $unpaid = LimoInvoice::query()->where('status', '!=', LimoInvoice::STATUS_PAID);
        $outstanding = (float) $unpaid->sum('total') - (float) (clone $unpaid)->sum('amount_paid');

        // Up to the END of the To day: a business database is SQLite, which keeps
        // a date as "2026-10-31 00:00:00" — text that sorts after "2026-10-31",
        // so every receipt and expense of the last day used to drop out.
        $window = [$this->from, $this->to . ' 23:59:59'];
        $collected = (float) LimoReceipt::query()->whereBetween('date', $window)->sum('amount');
        $expenses = (float) LimoExpense::query()->whereBetween('date', $window)->sum('amount');

        return [
            'bookingsCount' => (clone $bookings)->count(),
            'fareValue' => (float) (clone $bookings)->sum('fare'),
            'completed' => (clone $bookings)->where('status', LimoBooking::STATUS_COMPLETED)->count(),
            'collected' => $collected,
            'outstanding' => round($outstanding, 3),
            'expenses' => $expenses,
            'net' => round($collected - $expenses, 3),
        ];
    }

    public function render(): View
    {
        $data = ['summary' => [], 'bookings' => collect(), 'customers' => collect()];

        if ($this->tab === 'summary') {
            $data['summary'] = $this->summaryData();
        } elseif ($this->tab === 'bookings') {
            $data['bookings'] = LimoBooking::query()
                ->with(['customer:id,name', 'pickupLocation:id,name', 'dropoffLocation:id,name'])
                ->whereBetween('pickup_at', [$this->from . ' 00:00:00', $this->to . ' 23:59:59'])
                ->orderBy('pickup_at')
                ->get();
        } elseif ($this->tab === 'customers') {
            $data['customers'] = LimoBooking::query()
                ->with('customer:id,name,phone')
                ->whereBetween('pickup_at', [$this->from . ' 00:00:00', $this->to . ' 23:59:59'])
                ->whereNotNull('customer_id')
                ->selectRaw('customer_id, COUNT(*) as bookings_count, SUM(fare) as revenue')
                ->groupBy('customer_id')
                ->orderByDesc('revenue')
                ->get();
        }

        return view('limousine::reports', $data);
    }
}
