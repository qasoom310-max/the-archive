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
use Modules\Rental\Support\SalesReport;

/**
 * Rental reports: one page, four lenses (summary / orders / vehicles /
 * customers) over a shared pick-up date range. Read-only aggregates for the
 * purchasing & management team; the orders lens exports to CSV.
 */
#[Layout('components.layouts.app')]
#[Title('Reports')]
final class Reports extends Component
{
    /** summary | orders | vehicles | customers | targets */
    #[Url]
    public string $tab = 'summary';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    /** Targets lens uses its own month/year (not the from/to range). */
    #[Url]
    public int $targetYear = 0;

    /** -1 = unset (mount → current month); 0 = whole year; 1–12 = a single month. */
    #[Url]
    public int $targetMonth = -1;

    public function mount(): void
    {
        if ($this->from === '') {
            $this->from = now()->startOfMonth()->format('Y-m-d');
        }
        if ($this->to === '') {
            $this->to = now()->endOfMonth()->format('Y-m-d');
        }
        if ($this->targetYear === 0) {
            $this->targetYear = (int) now()->year;
        }
        if ($this->targetMonth === -1) {
            $this->targetMonth = (int) now()->month; // default to the current month
        }
    }

    /**
     * Per-car target achievement for a chosen month or the whole year. The car's
     * monthly target (×12 for a whole year) is compared against its net revenue
     * for that period (cancelled excluded, outside-car cost netted off, imported
     * history merged via {@see SalesReport}). Cars that missed sort to the top;
     * cars with no target set fall to the bottom.
     *
     * @return array{rows: list<array{name: string, target: float, expected: float, revenue: float, pct: float|null, hasTarget: bool, achieved: bool}>, targetCount: int, achievedCount: int, wholeYear: bool, month: int, year: int, years: list<int>}
     */
    private function targetsData(): array
    {
        $report = new SalesReport($this->targetYear);
        $matrix = $report->plateMatrix();               // [plate => [month => net revenue]]
        $wholeYear = $this->targetMonth < 1 || $this->targetMonth > 12;
        $factor = $wholeYear ? 12 : 1;

        $rows = Vehicle::query()
            ->owned()   // targets are for our own cars, not rented-in (outside) ones
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'plate_no', 'color', 'monthly_target'])
            ->map(function (Vehicle $v) use ($matrix, $wholeYear, $factor): array {
                $months = $matrix[$v->plate_no ?? '—'] ?? [];
                $revenue = $wholeYear ? array_sum($months) : (float) ($months[$this->targetMonth] ?? 0.0);
                $target = (float) $v->monthly_target;
                $expected = round($target * $factor, 3);
                $hasTarget = $target > 0;

                return [
                    'name' => $v->displayName(),
                    'target' => $target,
                    'expected' => $expected,
                    'revenue' => round((float) $revenue, 3),
                    'pct' => $hasTarget && $expected > 0 ? round((float) $revenue / $expected * 100) : null,
                    'hasTarget' => $hasTarget,
                    'achieved' => $hasTarget && (float) $revenue >= $expected,
                ];
            })
            ->all();

        usort($rows, static function (array $a, array $b): int {
            if ($a['hasTarget'] !== $b['hasTarget']) {
                return $a['hasTarget'] ? -1 : 1;            // targeted cars first
            }
            if (! $a['hasTarget']) {
                return strcmp($a['name'], $b['name']);       // no-target: alphabetical
            }
            if ($a['achieved'] !== $b['achieved']) {
                return $a['achieved'] ? 1 : -1;              // missed before achieved
            }

            return ($a['pct'] ?? 0) <=> ($b['pct'] ?? 0);    // worst shortfall first
        });

        $withTarget = array_filter($rows, static fn (array $r): bool => $r['hasTarget']);

        return [
            'rows' => $rows,
            'targetCount' => count($withTarget),
            'achievedCount' => count(array_filter($withTarget, static fn (array $r): bool => $r['achieved'])),
            'wholeYear' => $wholeYear,
            'month' => $this->targetMonth,
            'year' => $this->targetYear,
            'years' => $report->availableYears(),
        ];
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
            'targets' => ['rows' => [], 'targetCount' => 0, 'achievedCount' => 0, 'wholeYear' => true, 'month' => 0, 'year' => $this->targetYear, 'years' => []],
        ];

        if ($this->tab === 'targets') {
            $data['targets'] = $this->targetsData();
        } elseif ($this->tab === 'summary') {
            $data['summary'] = $this->summaryData();
        } elseif ($this->tab === 'orders') {
            $data['orders'] = RentalOrder::query()
                ->with(['customer:id,name', 'vehicle:id,name,plate_no,color'])
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
