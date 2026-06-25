<?php

declare(strict_types=1);

namespace Modules\Rental\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Rental\Models\RentalInvoice;
use Modules\Rental\Models\RentalMaintenance;
use Modules\Rental\Models\RentalOrder;
use Modules\Rental\Models\RentalReceipt;
use Modules\Rental\Models\Vehicle;

/**
 * Rental reports: one page, four lenses (summary / orders / vehicles /
 * customers) over a shared pick-up date range. Read-only aggregates for the
 * purchasing & management team; the orders lens exports to CSV.
 */
#[Layout('components.layouts.app')]
#[Title('Reports')]
final class Reports extends Component
{
    /** summary | orders | vehicles | customers */
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
        $ordersInRange = RentalOrder::query()
            ->whereBetween('start_date', [$this->from, $this->to]);

        $unpaid = RentalInvoice::query()->where('status', '!=', RentalInvoice::STATUS_PAID);
        $outstanding = (float) $unpaid->sum('total') - (float) (clone $unpaid)->sum('amount_paid');

        return [
            'ordersCount' => (clone $ordersInRange)->count(),
            'ordersRevenue' => (float) (clone $ordersInRange)->sum('total'),
            'activeNow' => RentalOrder::query()->where('state', RentalOrder::STATE_ACTIVE)->count(),
            'invoiced' => (float) RentalInvoice::query()->whereBetween('issue_date', [$this->from, $this->to])->sum('total'),
            'collected' => (float) RentalReceipt::query()->whereBetween('date', [$this->from, $this->to])->sum('amount'),
            'outstanding' => round($outstanding, 3),
            'maintenanceSpend' => (float) RentalMaintenance::query()->whereBetween('date', [$this->from, $this->to])->sum('cost'),
        ];
    }

    public function render(): View
    {
        $data = [
            'orders' => collect(),
            'vehicles' => collect(),
            'customers' => collect(),
            'summary' => [],
        ];

        if ($this->tab === 'summary') {
            $data['summary'] = $this->summaryData();
        } elseif ($this->tab === 'orders') {
            $data['orders'] = RentalOrder::query()
                ->with(['customer:id,name', 'vehicle:id,name'])
                ->whereBetween('start_date', [$this->from, $this->to])
                ->orderBy('start_date')
                ->get();
        } elseif ($this->tab === 'vehicles') {
            $rows = RentalOrder::query()
                ->whereBetween('start_date', [$this->from, $this->to])
                ->whereNotNull('vehicle_id')
                ->selectRaw('vehicle_id, COUNT(*) as orders_count, SUM(total) as revenue, SUM(days) as days_out')
                ->groupBy('vehicle_id')
                ->get()
                ->keyBy('vehicle_id');

            $data['vehicles'] = Vehicle::query()->orderBy('name')->get()->map(function (Vehicle $v) use ($rows): array {
                $row = $rows->get($v->id);

                return [
                    'name' => $v->displayName(),
                    'status' => $v->status,
                    'orders' => $row !== null ? (int) $row->getAttribute('orders_count') : 0,
                    'days' => $row !== null ? (int) $row->getAttribute('days_out') : 0,
                    'revenue' => $row !== null ? (float) $row->getAttribute('revenue') : 0.0,
                ];
            });
        } elseif ($this->tab === 'customers') {
            $data['customers'] = RentalOrder::query()
                ->with('customer:id,name,phone')
                ->whereBetween('start_date', [$this->from, $this->to])
                ->whereNotNull('customer_id')
                ->selectRaw('customer_id, COUNT(*) as orders_count, SUM(total) as revenue')
                ->groupBy('customer_id')
                ->orderByDesc('revenue')
                ->get();
        }

        return view('rental::reports', $data);
    }
}
