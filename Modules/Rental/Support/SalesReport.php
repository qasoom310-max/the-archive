<?php

declare(strict_types=1);

namespace Modules\Rental\Support;

use Illuminate\Support\Carbon;
use Modules\Rental\Models\RentalOrder;
use Modules\Rental\Models\RentalRevenueHistory;
use Modules\Rental\Models\Vehicle;

/**
 * Computes the booking-revenue matrix and seasonal analysis behind the Sales
 * page and its CSV export. Live bookings come from rental_orders; figures
 * imported from an older system are merged in from rental_revenue_history so the
 * matrix and seasonality reflect the full history.
 */
final class SalesReport
{
    /** @var array<int, string> */
    public const MONTHS = [
        1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'May', 6 => 'Jun',
        7 => 'Jul', 8 => 'Aug', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dec',
    ];

    public function __construct(private readonly int $year)
    {
    }

    /**
     * Revenue per car per month for the year (cancelled orders excluded), keyed
     * by plate so imported history merges with live bookings.
     *
     * @return array<string, array<int, float>>
     */
    public function plateMatrix(): array
    {
        $matrix = [];
        $plates = Vehicle::query()->pluck('plate_no', 'id'); // [vehicleId => plate|null]

        $orders = RentalOrder::query()
            ->where('state', '!=', RentalOrder::STATE_CANCELLED)
            ->whereBetween('start_date', [
                Carbon::create($this->year, 1, 1)->startOfDay(),
                Carbon::create($this->year, 12, 31)->endOfDay(),
            ])
            ->get(['vehicle_id', 'start_date', 'total']);

        foreach ($orders as $order) {
            if ($order->start_date === null) {
                continue;
            }
            $plate = $order->vehicle_id !== null ? $plates->get($order->vehicle_id) : null;
            $plate = is_string($plate) && $plate !== '' ? $plate : '—';
            $m = $order->start_date->month;
            $matrix[$plate][$m] = ($matrix[$plate][$m] ?? 0.0) + (float) $order->total;
        }

        foreach (RentalRevenueHistory::query()->where('year', $this->year)->get(['plate_no', 'month', 'amount']) as $row) {
            $plate = $row->plate_no ?? '—';
            $matrix[$plate][$row->month] = ($matrix[$plate][$row->month] ?? 0.0) + (float) $row->amount;
        }

        return $matrix;
    }

    /**
     * One display row per car (active cars plus any plate with history this year):
     * plate, name, expected annual (target × 12), the 12 months, and the total.
     *
     * @return list<array{plate: string, name: string, expected: float, months: array<int, float>, total: float}>
     */
    public function carRows(): array
    {
        $matrix = $this->plateMatrix();

        $vehicles = Vehicle::query()
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'plate_no', 'monthly_target']);

        $rows = [];
        $seenPlates = [];

        foreach ($vehicles as $vehicle) {
            $plate = $vehicle->plate_no ?? '—';
            $seenPlates[$plate] = true;
            $rows[] = $this->row($plate, $vehicle->name, (float) $vehicle->monthly_target * 12, $matrix[$plate] ?? []);
        }

        // Plates that only exist in imported history (cars sold / not in the fleet).
        foreach ($matrix as $plate => $months) {
            if (! isset($seenPlates[$plate])) {
                $rows[] = $this->row((string) $plate, __('Imported'), 0.0, $months);
            }
        }

        return $rows;
    }

    /**
     * @param  array<int, float>  $months
     * @return array{plate: string, name: string, expected: float, months: array<int, float>, total: float}
     */
    private function row(string $plate, string $name, float $expected, array $months): array
    {
        $cells = [];
        $total = 0.0;
        for ($m = 1; $m <= 12; $m++) {
            $val = (float) ($months[$m] ?? 0.0);
            $cells[$m] = $val;
            $total += $val;
        }

        return ['plate' => $plate, 'name' => $name, 'expected' => $expected, 'months' => $cells, 'total' => $total];
    }

    /**
     * Fleet revenue per month for the year.
     *
     * @return array<int, float>
     */
    public function fleetMonthly(): array
    {
        $fleet = array_fill(1, 12, 0.0);
        foreach ($this->plateMatrix() as $months) {
            foreach ($months as $m => $val) {
                $fleet[$m] += $val;
            }
        }

        return $fleet;
    }

    /**
     * Years with any bookings or imported history, plus the current and selected
     * year — newest first.
     *
     * @return list<int>
     */
    public function availableYears(): array
    {
        $years = RentalOrder::query()->whereNotNull('start_date')->get(['start_date'])
            ->map(static fn (RentalOrder $o): int => (int) $o->start_date?->year)->all();

        $years = array_merge($years, RentalRevenueHistory::query()->distinct()->pluck('year')->all());
        $years[] = (int) Carbon::now()->year;
        $years[] = $this->year;
        $years = array_values(array_unique(array_filter(array_map('intval', $years))));
        rsort($years);

        return $years;
    }

    /**
     * Average fleet revenue per calendar month across every year of history
     * (orders + imports), the peak months, whether there's enough data to judge,
     * and a banner when next month is historically a peak.
     *
     * @return array{avg: array<int, float>, peak: list<int>, hasHistory: bool, banner: array{month: int, avg: float}|null}
     */
    public function seasonality(): array
    {
        $byYearMonth = [];

        $orders = RentalOrder::query()
            ->where('state', '!=', RentalOrder::STATE_CANCELLED)
            ->whereNotNull('start_date')
            ->get(['start_date', 'total']);

        foreach ($orders as $order) {
            if ($order->start_date === null) {
                continue;
            }
            $byYearMonth[$order->start_date->year][$order->start_date->month]
                = ($byYearMonth[$order->start_date->year][$order->start_date->month] ?? 0.0) + (float) $order->total;
        }

        foreach (RentalRevenueHistory::query()->get(['year', 'month', 'amount']) as $row) {
            $byYearMonth[$row->year][$row->month] = ($byYearMonth[$row->year][$row->month] ?? 0.0) + (float) $row->amount;
        }

        $sum = array_fill(1, 12, 0.0);
        $count = array_fill(1, 12, 0);
        foreach ($byYearMonth as $months) {
            foreach ($months as $m => $val) {
                $sum[$m] += $val;
                $count[$m]++;
            }
        }

        $avg = [];
        foreach (range(1, 12) as $m) {
            $avg[$m] = $count[$m] > 0 ? round($sum[$m] / $count[$m], 3) : 0.0;
        }

        $populated = array_filter($avg, static fn (float $v): bool => $v > 0);
        $hasHistory = count($populated) >= 3;
        $overall = $populated !== [] ? array_sum($populated) / count($populated) : 0.0;

        $peak = $hasHistory && $overall > 0
            ? array_keys(array_filter($avg, static fn (float $v): bool => $v >= $overall * 1.15))
            : [];

        $banner = null;
        if ($hasHistory) {
            $nextMonth = (Carbon::now()->month % 12) + 1;
            if (in_array($nextMonth, $peak, true)) {
                $banner = ['month' => $nextMonth, 'avg' => $avg[$nextMonth]];
            }
        }

        return ['avg' => $avg, 'peak' => $peak, 'hasHistory' => $hasHistory, 'banner' => $banner];
    }
}
