<?php

declare(strict_types=1);

namespace Modules\Rental\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Rental\Models\RentalOrder;
use Modules\Rental\Models\Vehicle;

/**
 * Whether each car is worth owning - not just what it billed.
 *
 * The month-by-month revenue matrix the office already had answers "what did
 * this car invoice". It cannot answer the question the owner is actually
 * asking, because it ranks by total revenue: a car that earned 24,000 over 300
 * rented days is a WORSE asset than one that earned 17,000 over 120, and the
 * matrix puts them the other way round.
 *
 * So every car is also measured on:
 *
 *  - utilisation, the share of days it was actually on hire;
 *  - revenue per AVAILABLE day, which is the real ranking - a car earns nothing
 *    on the days it sits on the forecourt, and those days still cost money;
 *  - what is left after outside vendors and its own maintenance bills;
 *  - pace against its yearly target, judged against the part of the year that
 *    has actually gone, so a car is not called a failure every month until
 *    December;
 *  - idle cost, what the standing days would have earned at the car's own
 *    achieved rate. That is the number the owner can act on today.
 *
 * The verdict separates two failures that look identical in a revenue column
 * and need opposite fixes: a car that is not hired often enough (underused -
 * a marketing or pricing problem) and one that is hired constantly but cheaply
 * (behind - a rate problem).
 */
final class FleetPerformance
{
    /** A car this far below the fleet's own utilisation is underused. */
    private const UNDERUSED_AT = 0.75;

    /**
     * @param  int  $month  1-12 reads one month; 0 reads the whole year. In a
     *                      month every figure - days, earnings, costs, idle
     *                      cost - is that month's, and a car is judged against
     *                      its MONTHLY target rather than its yearly one.
     */
    public function __construct(private readonly int $year, private readonly int $month = 0) {}

    /**
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     summary: array<string, mixed>,
     *     year: int,
     *     month: int,
     *     months: int,
     *     hasLimo: bool
     * }
     */
    public function report(): array
    {
        $yearStart = CarbonImmutable::create($this->year, 1, 1)->startOfDay();
        $month = $this->month >= 1 && $this->month <= 12 ? $this->month : 0;
        $start = $month > 0 ? $yearStart->setMonth($month)->startOfMonth() : $yearStart;
        $end = $month > 0 ? $start->endOfMonth() : $yearStart->endOfYear();
        $today = CarbonImmutable::now();

        // A part-finished year is measured against the days that have actually
        // gone. Using all 365 would show every car at a third of its utilisation
        // in April and make the whole page read as a disaster every spring.
        $through = $today->lt($end) ? $today->endOfDay() : $end;
        $availableDays = $today->lt($start) ? 0 : (int) $start->diffInDays($through) + 1;

        $vehicles = Vehicle::query()
            ->owned()
            ->orderBy('name')
            ->get(['id', 'name', 'plate_no', 'color', 'monthly_target', 'yearly_target', 'daily_rate', 'active']);

        // The matrix always shows all twelve months, so earnings are read for
        // the whole year; the rest is read for the chosen period only.
        $earnings = $this->earnings($yearStart, $yearStart->endOfYear());
        $rented = $this->rentedDays($start, $end);
        $maintenance = $this->maintenance($start, $end);
        $limo = $this->limousine($start, $end);

        $rows = [];

        foreach ($vehicles as $vehicle) {
            $id = (int) $vehicle->getKey();

            // A retired car that earned nothing this year is history, not a
            // report line - it would sit at 0% and drag every fleet average.
            if (! $vehicle->active && ! isset($earnings[$id]) && ! isset($limo[$id])) {
                continue;
            }

            $rows[] = $this->row($vehicle, $id, $earnings, $rented, $maintenance, $limo, $availableDays, $start, $end, $through, $month);
        }

        // Money billed against no car at all. The old report carried it as
        // "Others" and dropping it would make the page disagree with the
        // dashboard, so it stays - with no target and no utilisation, because
        // there is no car to have either.
        if (isset($earnings[0])) {
            $rows[] = $this->othersRow($earnings[0], $month);
        }

        return [
            'rows' => $this->verdicts($rows),
            'summary' => $this->summary($rows, $availableDays),
            'year' => $this->year,
            'month' => $month,
            'months' => $availableDays > 0 ? (int) $start->diffInMonths($through) + 1 : 0,
            // The limousine column only appears once a car has actually earned
            // through it. Historic bookings carry no car, so on an imported
            // database it would otherwise be a column of zeroes.
            'hasLimo' => $limo !== [],
        ];
    }

    /**
     * @param  array<int, array{total: float, months: array<int, float>}>  $earnings
     * @param  array<int, int>  $rented
     * @param  array<int, float>  $maintenance
     * @param  array<int, float>  $limo
     * @return array<string, mixed>
     */
    private function row(
        Vehicle $vehicle,
        int $id,
        array $earnings,
        array $rented,
        array $maintenance,
        array $limo,
        int $availableDays,
        CarbonImmutable $start,
        CarbonImmutable $end,
        CarbonImmutable $through,
        int $month,
    ): array {
        $earned = $month > 0
            ? (float) ($earnings[$id]['months'][$month] ?? 0.0)
            : ($earnings[$id]['total'] ?? 0.0);
        $limoEarned = $limo[$id] ?? 0.0;
        $total = round($earned + $limoEarned, 3);

        $monthlyTarget = (float) $vehicle->monthly_target;
        $typedYearly = (float) $vehicle->yearly_target;
        // Twelve monthly targets is a fallback, not a plan - the page says so
        // rather than passing it off as a figure somebody chose.
        $yearlyTarget = $typedYearly > 0.0 ? $typedYearly : round($monthlyTarget * 12, 3);
        // A month is judged against the car's monthly target, a year against
        // its yearly one - never a year's target squeezed into one month.
        $target = $month > 0 ? $monthlyTarget : $yearlyTarget;

        // A retired car was not available all year, and we do not record when
        // it left, so it is reported on earnings only.
        $available = $vehicle->active ? $availableDays : 0;
        $rentedDays = min($rented[$id] ?? 0, $available > 0 ? $available : PHP_INT_MAX);
        $idleDays = max(0, $available - $rentedDays);

        $maintenanceCost = $maintenance[$id] ?? 0.0;

        // The rate this car actually achieved on the days it worked. Falling
        // back to its list rate means a car that never moved still shows the
        // full cost of standing still, which is the point of the figure.
        $perRentedDay = $rentedDays > 0 ? round($total / $rentedDays, 3) : (float) $vehicle->daily_rate;

        return [
            'id' => $id,
            'plate' => (string) ($vehicle->plate_no ?? ''),
            'name' => (string) $vehicle->name,
            'color' => (string) ($vehicle->color ?? ''),
            'retired' => ! $vehicle->active,
            'months' => $earnings[$id]['months'] ?? array_fill(1, 12, 0.0),
            'earned' => $earned,
            'limo' => $limoEarned,
            'total' => $total,
            'monthlyTarget' => $monthlyTarget,
            'yearlyTarget' => $yearlyTarget,
            'yearlyDerived' => $typedYearly <= 0.0 && $monthlyTarget > 0.0,
            'target' => $target,
            'rentedDays' => $rentedDays,
            'availableDays' => $available,
            'idleDays' => $idleDays,
            'utilisation' => $available > 0 ? (int) round($rentedDays / $available * 100) : null,
            'perAvailableDay' => $available > 0 ? round($total / $available, 3) : 0.0,
            'perRentedDay' => $perRentedDay,
            'maintenance' => $maintenanceCost,
            'net' => round($total - $maintenanceCost, 3),
            'attainment' => $target > 0.0 ? (int) round($total / $target * 100) : null,
            'pace' => $this->pace($total, $target, $start, $end, $through),
            'idleCost' => round($idleDays * $perRentedDay, 3),
            'verdict' => 'notarget',
        ];
    }

    /**
     * @param  array{total: float, months: array<int, float>}  $unassigned
     * @return array<string, mixed>
     */
    private function othersRow(array $unassigned, int $month): array
    {
        $earned = $month > 0 ? (float) ($unassigned['months'][$month] ?? 0.0) : $unassigned['total'];

        return [
            'id' => null,
            'plate' => '',
            'name' => __('Others (no car on the order)'),
            'color' => '',
            'retired' => false,
            'months' => $unassigned['months'],
            'earned' => $earned,
            'limo' => 0.0,
            'total' => $earned,
            'monthlyTarget' => 0.0,
            'yearlyTarget' => 0.0,
            'yearlyDerived' => false,
            'target' => 0.0,
            'rentedDays' => 0,
            'availableDays' => 0,
            'idleDays' => 0,
            'utilisation' => null,
            'perAvailableDay' => 0.0,
            'perRentedDay' => 0.0,
            'maintenance' => 0.0,
            'net' => $earned,
            'attainment' => null,
            'pace' => null,
            'idleCost' => 0.0,
            'verdict' => 'notarget',
        ];
    }

    /**
     * Two failures that look the same in a revenue column and need opposite
     * fixes: a car nobody hires, and a car everybody hires too cheaply.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function verdicts(array $rows): array
    {
        $usable = array_filter($rows, static fn (array $r): bool => $r['utilisation'] !== null);
        $fleetUtilisation = $usable === []
            ? 0.0
            : array_sum(array_column($usable, 'utilisation')) / count($usable);

        foreach ($rows as $i => $row) {
            $rows[$i]['verdict'] = match (true) {
                // Costs more to keep than it brings in. Nothing else matters.
                $row['net'] < 0.0 => 'losing',
                $row['target'] <= 0.0 => 'notarget',
                $row['pace'] !== null && $row['pace'] >= 100 => 'carrying',
                $row['utilisation'] !== null && $fleetUtilisation > 0.0
                    && $row['utilisation'] < $fleetUtilisation * self::UNDERUSED_AT => 'underused',
                default => 'behind',
            };
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function summary(array $rows, int $availableDays): array
    {
        $cars = array_values(array_filter($rows, static fn (array $r): bool => $r['id'] !== null));
        $live = array_values(array_filter($cars, static fn (array $r): bool => $r['availableDays'] > 0));

        $total = round(array_sum(array_column($rows, 'total')), 3);
        $rentedDays = (int) array_sum(array_column($live, 'rentedDays'));
        $fleetDays = (int) array_sum(array_column($live, 'availableDays'));
        $idleDays = max(0, $fleetDays - $rentedDays);

        $ranked = $live;
        usort($ranked, static fn (array $a, array $b): int => $b['perAvailableDay'] <=> $a['perAvailableDay']);

        $counts = ['carrying' => 0, 'behind' => 0, 'underused' => 0, 'losing' => 0, 'notarget' => 0];
        foreach ($this->verdicts($rows) as $row) {
            if ($row['id'] !== null) {
                $counts[$row['verdict']]++;
            }
        }

        return [
            'cars' => count($cars),
            'total' => $total,
            'maintenance' => round(array_sum(array_column($rows, 'maintenance')), 3),
            'net' => round(array_sum(array_column($rows, 'net')), 3),
            'target' => round(array_sum(array_column($cars, 'target')), 3),
            'rentedDays' => $rentedDays,
            'availableDays' => $fleetDays,
            'idleDays' => $idleDays,
            'utilisation' => $fleetDays > 0 ? (int) round($rentedDays / $fleetDays * 100) : null,
            // The headline: what standing still cost, at each car's own rate.
            'idleCost' => round(array_sum(array_column($live, 'idleCost')), 3),
            'best' => $ranked[0] ?? null,
            'worst' => $ranked === [] ? null : $ranked[count($ranked) - 1],
            'counts' => $counts,
            'days' => $availableDays,
        ];
    }

    /**
     * Attainment against the share of the period that has actually elapsed, so
     * a car on schedule reads 100 halfway through a month (or a year) exactly
     * as it does at the end of it. A period not yet started has no pace.
     */
    private function pace(float $earned, float $target, CarbonImmutable $start, CarbonImmutable $end, CarbonImmutable $through): ?int
    {
        if ($target <= 0.0 || $through->lt($start)) {
            return null;
        }

        $periodDays = (int) $start->diffInDays($end) + 1;
        $elapsed = (int) $start->diffInDays($through) + 1;
        $expected = $target * ($elapsed / max(1, $periodDays));

        return $expected <= 0.0 ? null : (int) round($earned / $expected * 100);
    }

    /**
     * Net revenue per car per month. Keyed by vehicle id, with 0 standing for
     * orders that carry no car.
     *
     * @return array<int, array{total: float, months: array<int, float>}>
     */
    private function earnings(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $out = [];

        RentalOrder::query()
            ->where('state', '!=', RentalOrder::STATE_CANCELLED)
            ->whereBetween('start_date', [$start->toDateString(), $end->endOfDay()->toDateTimeString()])
            ->select(['id', 'vehicle_id', 'start_date', 'total', 'outside_cost'])
            ->chunkById(1000, function ($orders) use (&$out): void {
                foreach ($orders as $order) {
                    $id = (int) ($order->vehicle_id ?? 0);
                    $month = (int) CarbonImmutable::parse((string) $order->start_date)->format('n');
                    // Net of what outside vendors take, matching every other
                    // revenue figure in the system.
                    $net = round((float) $order->total - (float) $order->outside_cost, 3);

                    $out[$id] ??= ['total' => 0.0, 'months' => array_fill(1, 12, 0.0)];
                    $out[$id]['total'] = round($out[$id]['total'] + $net, 3);
                    $out[$id]['months'][$month] = round($out[$id]['months'][$month] + $net, 3);
                }
            });

        return $out;
    }

    /**
     * Days each car was actually on hire inside the window.
     *
     * An order is clamped to the window rather than counted whole, so a hire
     * running from December into January is not credited twice.
     *
     * @return array<int, int>
     */
    private function rentedDays(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $out = [];

        RentalOrder::query()
            ->where('state', '!=', RentalOrder::STATE_CANCELLED)
            ->whereNotNull('vehicle_id')
            ->whereNotNull('start_date')
            ->where('start_date', '<=', $end->endOfDay()->toDateTimeString())
            ->select(['id', 'vehicle_id', 'start_date', 'end_date'])
            ->chunkById(1000, function ($orders) use (&$out, $start, $end): void {
                foreach ($orders as $order) {
                    $from = CarbonImmutable::parse((string) $order->start_date)->startOfDay();
                    // An open hire is still running: count it up to today.
                    $to = $order->end_date === null
                        ? CarbonImmutable::now()->startOfDay()
                        : CarbonImmutable::parse((string) $order->end_date)->startOfDay();

                    if ($to->lt($from)) {
                        $to = $from;
                    }

                    $from = $from->lt($start) ? $start : $from;
                    $to = $to->gt($end) ? $end : $to;

                    if ($to->lt($from)) {
                        continue;
                    }

                    $id = (int) $order->vehicle_id;
                    $out[$id] = ($out[$id] ?? 0) + (int) $from->diffInDays($to) + 1;
                }
            });

        return $out;
    }

    /**
     * What each car cost in service and repairs inside the window.
     *
     * @return array<int, float>
     */
    private function maintenance(CarbonImmutable $start, CarbonImmutable $end): array
    {
        if (! Schema::hasTable('rental_maintenance')) {
            return [];
        }

        return DB::table('rental_maintenance')
            ->whereNotNull('vehicle_id')
            ->whereBetween('date', [$start->toDateString(), $end->endOfDay()->toDateTimeString()])
            ->selectRaw('vehicle_id, COALESCE(SUM(cost), 0) as spent')
            ->groupBy('vehicle_id')
            ->pluck('spent', 'vehicle_id')
            ->map(static fn (mixed $v): float => round((float) $v, 3))
            ->all();
    }

    /**
     * The same physical car also earns on the limousine desk, which books cars
     * out of this fleet. Nobody could see a car's whole contribution before,
     * because the two apps reported separately.
     *
     * Historic imported legs carry no car, so this is empty on an imported
     * database and the column is hidden rather than shown as zeroes.
     *
     * @return array<int, float>
     */
    private function limousine(CarbonImmutable $start, CarbonImmutable $end): array
    {
        if (! Schema::hasTable('limo_legs')) {
            return [];
        }

        return DB::table('limo_legs')
            ->whereNotNull('car_id')
            ->whereNotNull('start_at')
            ->whereBetween('start_at', [$start->toDateString(), $end->endOfDay()->toDateTimeString()])
            ->selectRaw('car_id, COALESCE(SUM(net_amount), 0) as earned')
            ->groupBy('car_id')
            ->pluck('earned', 'car_id')
            ->map(static fn (mixed $v): float => round((float) $v, 3))
            ->filter(static fn (float $v): bool => $v > 0.0)
            ->all();
    }
}
