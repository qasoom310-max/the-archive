<?php

declare(strict_types=1);

namespace Modules\Rental\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Rental\Models\RentalOrder;
use Modules\Rental\Models\Vehicle;

/**
 * Sales — the per-car month-by-month booking-revenue matrix (Expected vs actual,
 * by year) plus seasonal analysis: which months are historically strong or dead,
 * and a heads-up when a peak season is about to start.
 */
#[Layout('components.layouts.app')]
#[Title('Sales')]
final class Sales extends Component
{
    /** @var array<int, string> */
    public const MONTHS = [
        1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'May', 6 => 'Jun',
        7 => 'Jul', 8 => 'Aug', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dec',
    ];

    #[Url]
    public int $year = 0;

    public function mount(): void
    {
        if ($this->year === 0) {
            $this->year = (int) Carbon::now()->year;
        }
    }

    public function render(): View
    {
        $matrix = $this->yearMatrix();          // [vehicleId][month] => revenue
        $fleetMonthly = array_fill(1, 12, 0.0); // fleet revenue per month this year
        foreach ($matrix as $months) {
            foreach ($months as $m => $val) {
                $fleetMonthly[$m] += $val;
            }
        }

        $cars = $this->carRows($matrix);

        // Best / slowest month of the selected year (only among months with data).
        $withData = array_filter($fleetMonthly, static fn (float $v): bool => $v > 0);
        $bestMonth = $withData !== [] ? (int) array_keys($fleetMonthly, max($withData), true)[0] : null;
        $deadMonth = $withData !== [] ? (int) array_keys($fleetMonthly, min($withData), true)[0] : null;

        [$seasonalAvg, $peakMonths, $hasHistory, $banner] = $this->seasonality();

        return view('rental::sales', [
            'months' => self::MONTHS,
            'cars' => $cars,
            'fleetMonthly' => $fleetMonthly,
            'fleetTotal' => array_sum($fleetMonthly),
            'bestMonth' => $bestMonth,
            'deadMonth' => $deadMonth,
            'availableYears' => $this->availableYears(),
            'seasonalAvg' => $seasonalAvg,
            'seasonalMax' => $seasonalAvg !== [] ? max($seasonalAvg) : 0.0,
            'peakMonths' => $peakMonths,
            'hasHistory' => $hasHistory,
            'seasonBanner' => $banner,
        ]);
    }

    /**
     * Revenue per car per month for the selected year (cancelled orders excluded).
     *
     * @return array<int, array<int, float>>
     */
    private function yearMatrix(): array
    {
        $rows = RentalOrder::query()
            ->where('state', '!=', RentalOrder::STATE_CANCELLED)
            ->whereBetween('start_date', [
                Carbon::create($this->year, 1, 1)->startOfDay(),
                Carbon::create($this->year, 12, 31)->endOfDay(),
            ])
            ->get(['vehicle_id', 'start_date', 'total']);

        $matrix = [];
        foreach ($rows as $row) {
            if ($row->vehicle_id === null || $row->start_date === null) {
                continue;
            }
            $vid = $row->vehicle_id;
            $m = $row->start_date->month;
            $matrix[$vid][$m] = ($matrix[$vid][$m] ?? 0.0) + (float) $row->total;
        }

        return $matrix;
    }

    /**
     * One display row per car: plate, name, expected annual (target × 12), the 12
     * monthly figures, and the year total. Active cars plus any car with bookings
     * this year (so a now-inactive car's history still shows).
     *
     * @param  array<int, array<int, float>>  $matrix
     * @return list<array{plate: ?string, name: string, expected: float, months: array<int, float>, total: float}>
     */
    private function carRows(array $matrix): array
    {
        $ids = array_values(array_unique([
            ...Vehicle::query()->where('active', true)->pluck('id')->all(),
            ...array_keys($matrix),
        ]));

        $vehicles = Vehicle::query()->whereIn('id', $ids)->orderBy('name')->get(['id', 'name', 'plate_no', 'monthly_target']);

        $cars = [];
        foreach ($vehicles as $vehicle) {
            $months = [];
            $total = 0.0;
            for ($m = 1; $m <= 12; $m++) {
                $val = (float) ($matrix[$vehicle->id][$m] ?? 0.0);
                $months[$m] = $val;
                $total += $val;
            }
            $cars[] = [
                'plate' => $vehicle->plate_no,
                'name' => $vehicle->name,
                'expected' => (float) $vehicle->monthly_target * 12,
                'months' => $months,
                'total' => $total,
            ];
        }

        return $cars;
    }

    /**
     * Years that have bookings, plus the current year — newest first.
     *
     * @return list<int>
     */
    private function availableYears(): array
    {
        $years = RentalOrder::query()
            ->whereNotNull('start_date')
            ->get(['start_date'])
            ->map(static fn (RentalOrder $o): int => (int) $o->start_date?->year)
            ->filter()
            ->unique()
            ->all();

        $years[] = (int) Carbon::now()->year;
        $years[] = $this->year;
        $years = array_values(array_unique(array_filter($years)));
        rsort($years);

        return $years;
    }

    /**
     * Average fleet revenue per calendar month across every year of history, the
     * peak months (well above the average), whether there's enough data to judge,
     * and a banner when next month is historically a peak.
     *
     * @return array{0: array<int, float>, 1: list<int>, 2: bool, 3: array{month: int, avg: float}|null}
     */
    private function seasonality(): array
    {
        $rows = RentalOrder::query()
            ->where('state', '!=', RentalOrder::STATE_CANCELLED)
            ->whereNotNull('start_date')
            ->get(['start_date', 'total']);

        // Fleet revenue per [year][month], then average each calendar month across
        // the years that actually have data for it.
        $byYearMonth = [];
        foreach ($rows as $row) {
            if ($row->start_date === null) {
                continue;
            }
            $byYearMonth[$row->start_date->year][$row->start_date->month]
                = ($byYearMonth[$row->start_date->year][$row->start_date->month] ?? 0.0) + (float) $row->total;
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
        // Need a few months of history before "seasons" mean anything.
        $hasHistory = count($populated) >= 3;
        $overall = $populated !== [] ? array_sum($populated) / count($populated) : 0.0;

        $peakMonths = $hasHistory && $overall > 0
            ? array_keys(array_filter($avg, static fn (float $v): bool => $v >= $overall * 1.15))
            : [];

        $banner = null;
        if ($hasHistory) {
            $nextMonth = (Carbon::now()->month % 12) + 1;
            if (in_array($nextMonth, $peakMonths, true)) {
                $banner = ['month' => $nextMonth, 'avg' => $avg[$nextMonth]];
            }
        }

        return [$avg, $peakMonths, $hasHistory, $banner];
    }
}
