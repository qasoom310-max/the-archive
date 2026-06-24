<?php

declare(strict_types=1);

namespace Modules\Limousine\Livewire;

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
    /** summary | bookings | customers */
    #[Url]
    public string $tab = 'summary';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public function mount(): void
    {
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

        $collected = (float) LimoReceipt::query()->whereBetween('date', [$this->from, $this->to])->sum('amount');
        $expenses = (float) LimoExpense::query()->whereBetween('date', [$this->from, $this->to])->sum('amount');

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
