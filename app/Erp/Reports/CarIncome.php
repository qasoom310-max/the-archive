<?php

declare(strict_types=1);

namespace App\Erp\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What each car brought in, month by month, from BOTH desks at once.
 *
 * The owner sets one target per car for the year and does not care which desk
 * the money came through. Rent A Car and Limousine each had their own page, so
 * a car's whole year could only be read by adding two screens up by hand.
 * This is the one schedule: a row per car, a column per month, rent and
 * limousine side by side, against the car's yearly target.
 *
 * Counted the same way as the two pages it joins, so the three never disagree:
 *  - a hire counts in the month it STARTS, at its total less what an outside
 *    owner takes, unless the order is cancelled;
 *  - a limousine trip counts in the month it RUNS, at its net amount, when it
 *    belongs to a booking (not a quotation) and neither is cancelled.
 * That is work done, not cash collected.
 *
 * A trip only reaches a car when the car was assigned to it. Trips with no
 * car, and hires with no car, are kept as their own rows rather than dropped,
 * so the bottom line is the whole of both desks.
 *
 * Lives in the core and reads plain tables, each guarded: a database running
 * only one of the two apps still gets its half.
 */
final class CarIncome
{
    public const OWN = 'own';

    public const OUTSIDE = 'outside';

    /**
     * @param string       $fleet         which schedule: our own cars, or cars rented in from
     *                                    other companies. They are never mixed: an outside car
     *                                    earns only its mark-up and has no target of ours, so
     *                                    it would drag every figure of the owned fleet.
     * @param list<string> $categoryOrder the order the outside categories are listed in
     */
    public function __construct(
        private readonly int $year,
        private readonly string $fleet = self::OWN,
        private readonly array $categoryOrder = [],
    ) {}

    /**
     * @return array{
     *     year: int,
     *     available: bool,
     *     fleet: string,
     *     counts: array{own: int, outside: int},
     *     cars: list<array<string, mixed>>,
     *     groups: list<array<string, mixed>>,
     *     loose: list<array<string, mixed>>,
     *     totals: array<string, mixed>,
     *     hasRent: bool,
     *     hasLimo: bool,
     * }
     */
    public function report(): array
    {
        $rent = $this->rent();
        $limo = $this->limousine();

        $cars = [];

        foreach ($this->vehicles() as $vehicle) {
            $id = (int) $vehicle->id;
            $months = $this->months($rent[$id] ?? [], $limo[$id] ?? []);
            $total = $this->sum($months);

            // A retired car that brought nothing in is noise on a schedule.
            if (! (bool) $vehicle->active && $total['total'] <= 0.0) {
                continue;
            }

            $typed = (float) $vehicle->yearly_target;
            $monthly = (float) $vehicle->monthly_target;
            // Twelve monthly targets stand in only when no yearly one was set.
            $target = $typed > 0.0 ? $typed : round($monthly * 12, 3);

            $cars[] = [
                'id' => $id,
                'outside' => (bool) $vehicle->is_outside,
                'category' => (string) ($vehicle->category ?? ''),
                'name' => (string) $vehicle->name,
                'plate' => (string) ($vehicle->plate_no ?? ''),
                'retired' => ! (bool) $vehicle->active,
                'months' => $months,
                'rent' => $total['rent'],
                'limo' => $total['limo'],
                'total' => $total['total'],
                'target' => $target,
                'percent' => $target > 0.0 ? (int) round($total['total'] / $target * 100) : null,
                'remaining' => $target > 0.0 ? round(max(0.0, $target - $total['total']), 3) : null,
            ];
        }

        usort($cars, static fn (array $a, array $b): int => [$b['total'], $a['name']] <=> [$a['total'], $b['name']]);

        $outside = $this->fleet === self::OUTSIDE;
        $counts = [
            'own' => count(array_filter($cars, static fn (array $c): bool => ! $c['outside'])),
            'outside' => count(array_filter($cars, static fn (array $c): bool => $c['outside'])),
        ];
        $cars = array_values(array_filter($cars, static fn (array $c): bool => $c['outside'] === $outside));

        // Money that belongs to no car. Shown, never hidden: leaving it out
        // would make this page disagree with every other total in the system.
        $loose = [];
        // It is listed with our own cars: a trip or hire that names no car is
        // the office's to chase, and listing it twice would double the total.
        foreach ($outside ? [] : ['rent' => $rent[0] ?? [], 'limo' => $limo[0] ?? []] as $kind => $byMonth) {
            $months = $kind === 'rent' ? $this->months($byMonth, []) : $this->months([], $byMonth);
            $total = $this->sum($months);

            if ($total['total'] > 0.0) {
                $loose[] = ['kind' => $kind, 'months' => $months] + $total;
            }
        }

        $all = array_merge($cars, $loose);
        $totalMonths = [];
        for ($m = 1; $m <= 12; $m++) {
            $r = round(array_sum(array_map(static fn (array $row): float => $row['months'][$m]['rent'], $all)), 3);
            $l = round(array_sum(array_map(static fn (array $row): float => $row['months'][$m]['limo'], $all)), 3);
            $totalMonths[$m] = ['rent' => $r, 'limo' => $l, 'total' => round($r + $l, 3)];
        }

        $grand = $this->sum($totalMonths);
        $target = round(array_sum(array_column($cars, 'target')), 3);
        // Cars are judged on their own money; loose money sits on no car and
        // so cannot count toward any car's target.
        $onCars = round(array_sum(array_column($cars, 'total')), 3);

        return [
            'year' => $this->year,
            // A year in which nothing earned gets one sentence, not a grid of dashes.
            'available' => $grand['total'] > 0.0,
            'fleet' => $outside ? self::OUTSIDE : self::OWN,
            'counts' => $counts,
            'cars' => $cars,
            'groups' => $outside ? $this->groups($cars) : [],
            'loose' => $loose,
            'totals' => $grand + [
                'months' => $totalMonths,
                'target' => $target,
                'onCars' => $onCars,
                'percent' => $target > 0.0 ? (int) round($onCars / $target * 100) : null,
                'remaining' => $target > 0.0 ? round(max(0.0, $target - $onCars), 3) : null,
            ],
            'hasRent' => $grand['rent'] > 0.0,
            'hasLimo' => $grand['limo'] > 0.0,
        ];
    }

    /**
     * Outside cars folded into their categories: one row per category with the
     * cars inside it. Outside cars are too many to read one by one, so the
     * schedule lists the categories and opens one on demand. A car with no
     * category is kept, under '' — never dropped.
     *
     * @param list<array<string, mixed>> $cars
     *
     * @return list<array<string, mixed>>
     */
    private function groups(array $cars): array
    {
        $byCategory = [];
        foreach ($cars as $car) {
            $byCategory[(string) $car['category']][] = $car;
        }

        // The owner's order first, then anything else by name, then "none".
        $keys = array_keys($byCategory);
        $rank = array_flip($this->categoryOrder);
        usort($keys, static fn (string $a, string $b): int => [$a === '' ? 1 : 0, $rank[$a] ?? PHP_INT_MAX, $a] <=> [$b === '' ? 1 : 0, $rank[$b] ?? PHP_INT_MAX, $b]);

        $groups = [];
        foreach ($keys as $key) {
            $members = $byCategory[$key];
            $months = [];
            for ($m = 1; $m <= 12; $m++) {
                $r = round(array_sum(array_map(static fn (array $c): float => $c['months'][$m]['rent'], $members)), 3);
                $l = round(array_sum(array_map(static fn (array $c): float => $c['months'][$m]['limo'], $members)), 3);
                $months[$m] = ['rent' => $r, 'limo' => $l, 'total' => round($r + $l, 3)];
            }

            $sum = $this->sum($months);
            $target = round(array_sum(array_column($members, 'target')), 3);

            $groups[] = [
                'category' => $key,
                'count' => count($members),
                'cars' => $members,
                'months' => $months,
                'target' => $target,
                'percent' => $target > 0.0 ? (int) round($sum['total'] / $target * 100) : null,
                'remaining' => $target > 0.0 ? round(max(0.0, $target - $sum['total']), 3) : null,
            ] + $sum;
        }

        return $groups;
    }

    /**
     * @param array<int, float> $rent
     * @param array<int, float> $limo
     *
     * @return array<int, array{rent: float, limo: float, total: float}>
     */
    private function months(array $rent, array $limo): array
    {
        $out = [];
        for ($m = 1; $m <= 12; $m++) {
            $r = round($rent[$m] ?? 0.0, 3);
            $l = round($limo[$m] ?? 0.0, 3);
            $out[$m] = ['rent' => $r, 'limo' => $l, 'total' => round($r + $l, 3)];
        }

        return $out;
    }

    /**
     * @param array<int, array{rent: float, limo: float, total: float}> $months
     *
     * @return array{rent: float, limo: float, total: float}
     */
    private function sum(array $months): array
    {
        $rent = round(array_sum(array_column($months, 'rent')), 3);
        $limo = round(array_sum(array_column($months, 'limo')), 3);

        return ['rent' => $rent, 'limo' => $limo, 'total' => round($rent + $limo, 3)];
    }

    /**
     * @return list<object>
     */
    private function vehicles(): array
    {
        if (! Schema::hasTable('rental_vehicles')) {
            return [];
        }

        return DB::table('rental_vehicles')
            ->orderBy('name')
            ->get(['id', 'name', 'plate_no', 'active', 'is_outside', 'category', 'monthly_target', 'yearly_target'])
            ->all();
    }

    /**
     * Hire money by car (0 = no car) and month.
     *
     * @return array<int, array<int, float>>
     */
    private function rent(): array
    {
        if (! Schema::hasTable('rental_orders')) {
            return [];
        }

        [$start, $end] = $this->window();
        $out = [];

        DB::table('rental_orders')
            ->where('state', '!=', 'cancelled')
            ->whereBetween('start_date', [$start, $end])
            ->orderBy('id')
            ->select(['id', 'vehicle_id', 'start_date', 'total', 'outside_cost'])
            ->chunkById(1000, function ($orders) use (&$out): void {
                foreach ($orders as $order) {
                    $car = (int) ($order->vehicle_id ?? 0);
                    $month = (int) CarbonImmutable::parse((string) $order->start_date)->format('n');
                    $out[$car][$month] = round(($out[$car][$month] ?? 0.0) + (float) $order->total - (float) $order->outside_cost, 3);
                }
            });

        return $out;
    }

    /**
     * Limousine trip money by car (0 = no car assigned) and month.
     *
     * @return array<int, array<int, float>>
     */
    private function limousine(): array
    {
        if (! Schema::hasTable('limo_legs') || ! Schema::hasTable('limo_bookings')) {
            return [];
        }

        [$start, $end] = $this->window();
        $out = [];

        DB::table('limo_legs as l')
            ->join('limo_bookings as b', 'b.id', '=', 'l.legable_id')
            // Legs are shared with quotations; only a booking's legs earned.
            ->where('l.legable_type', 'Modules\\Limousine\\Models\\LimoBooking')
            ->where('b.status', '!=', 'cancelled')
            ->where(static fn ($q) => $q->whereNull('l.status')->orWhere('l.status', '!=', 'cancelled'))
            ->whereNotNull('l.start_at')
            ->whereBetween('l.start_at', [$start, $end])
            ->orderBy('l.id')
            ->select(['l.id', 'l.car_id', 'l.start_at', 'l.net_amount'])
            ->chunkById(1000, function ($legs) use (&$out): void {
                foreach ($legs as $leg) {
                    $car = (int) ($leg->car_id ?? 0);
                    $month = (int) CarbonImmutable::parse((string) $leg->start_at)->format('n');
                    $out[$car][$month] = round(($out[$car][$month] ?? 0.0) + (float) $leg->net_amount, 3);
                }
            }, 'l.id', 'id');

        return $out;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function window(): array
    {
        $start = CarbonImmutable::create($this->year, 1, 1)->startOfDay();

        return [$start->toDateString(), $start->endOfYear()->toDateTimeString()];
    }
}
