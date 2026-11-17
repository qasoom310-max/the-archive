<?php

declare(strict_types=1);

namespace App\Erp\Targets;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What each car is expected to earn: one figure for the year, typed once.
 *
 * Everything else is worked out from it, so nobody keeps a second set of
 * numbers in step by hand:
 *
 *  - NEXT YEAR. On 1 January a car that reached its target carries that
 *    target plus 10%. A car that missed keeps the same target. This repeats
 *    every year until the manager types a new figure, which then becomes the
 *    starting point.
 *  - EACH MONTH. The year is shared out the way the whole owned fleet's sales
 *    fell last year, so a busy month carries more of the target than a quiet
 *    one. The fleet is used rather than the one car, because a single car
 *    that sat in the garage last March would otherwise be given nothing to
 *    do in March for ever after.
 *
 * "Reached" counts everything a car brought in over the year: its hires (net
 * of what an outside owner takes), its limousine trips, and any figures
 * imported from the old system. Work done, not cash collected.
 *
 * Only the typed years are stored (rental_vehicle_targets). A car with no
 * typed year at all falls back to the two old columns on the car, read as a
 * flat figure, so a database that has not been migrated yet still reports.
 *
 * Lives in the core and reads plain tables, each guarded, like the reports
 * that use it. One instance remembers what it has read; make one per report.
 */
final class CarTargets
{
    /** What a reached target grows by in the new year. */
    public const RAISE = 0.10;

    /** @var array<int, array<int, float>>|null car => year => typed amount */
    private ?array $typed = null;

    /** @var array<int, float>|null car => flat figure from the old columns */
    private ?array $legacy = null;

    /** @var array<int, array<int, array<int, float>>> year => car => month => earned */
    private array $earned = [];

    /** @var array<int, array{shares: array<int, float>, equal: bool}> */
    private array $shares = [];

    /** @var array<int, array<int, array{amount: float, source: string, from: float|null, fromYear: int|null}>> */
    private array $resolved = [];

    /** @var array{owned: array<int, true>, outside: array<int, true>, plates: array<string, int>}|null */
    private ?array $cars = null;

    /** The car's target for a year. 0 means it has none. */
    public function yearly(int $carId, int $year): float
    {
        return $this->resolve($carId, $year)['amount'];
    }

    /**
     * The target and where it came from:
     *  - typed:  the manager set this year's figure;
     *  - raised: last year's was reached, so this is that plus 10%;
     *  - kept:   last year's was missed, so it stays the same;
     *  - none:   the car has no target.
     *
     * @return array{amount: float, source: string, from: float|null, fromYear: int|null}
     */
    public function resolve(int $carId, int $year): array
    {
        return $this->resolved[$carId][$year] ??= $this->work($carId, $year);
    }

    /**
     * The year's target shared over its twelve months. They always add up to
     * the yearly figure exactly: December takes whatever rounding leaves.
     *
     * @return array<int, float>
     */
    public function months(int $carId, int $year): array
    {
        $yearly = $this->yearly($carId, $year);
        $shares = $this->shares($year)['shares'];
        $out = [];
        $given = 0.0;

        for ($m = 1; $m <= 11; $m++) {
            $out[$m] = round($yearly * $shares[$m], 3);
            $given += $out[$m];
        }
        $out[12] = round(max(0.0, $yearly - $given), 3);

        return $out;
    }

    public function monthly(int $carId, int $year, int $month): float
    {
        return $this->months($carId, $year)[$month] ?? 0.0;
    }

    /**
     * How a year is shared over its months, from what the owned fleet sold the
     * year before. `equal` is true when there was nothing usable to follow: no
     * sales at all last year, or a month with none. A whole fleet earning
     * nothing in a month means the records are missing, not that the month is
     * dead, and following it would set that month's target to zero.
     *
     * @return array{shares: array<int, float>, equal: bool}
     */
    public function shares(int $year): array
    {
        if (isset($this->shares[$year])) {
            return $this->shares[$year];
        }

        $cars = $this->cars();
        $totals = array_fill(1, 12, 0.0);

        foreach ($this->earnedByCar($year - 1) as $carId => $months) {
            // Our own cars, and old-system figures for cars since sold (-1).
            // Never an outside car, and never money booked against no car.
            if ($carId !== -1 && ! isset($cars['owned'][$carId])) {
                continue;
            }
            foreach ($months as $m => $amount) {
                $totals[$m] += $amount;
            }
        }

        $sum = array_sum($totals);

        if ($sum <= 0.0 || min($totals) <= 0.0) {
            return $this->shares[$year] = ['shares' => array_fill(1, 12, 1 / 12), 'equal' => true];
        }

        return $this->shares[$year] = [
            'shares' => array_map(static fn (float $v): float => $v / $sum, $totals),
            'equal' => false,
        ];
    }

    /** Everything a car brought in over a year. */
    public function earnedInYear(int $carId, int $year): float
    {
        return round(array_sum($this->earnedByCar($year)[$carId] ?? []), 3);
    }

    /** Everything a car brought in over one month. */
    public function earnedInMonth(int $carId, int $year, int $month): float
    {
        return round($this->earnedByCar($year)[$carId][$month] ?? 0.0, 3);
    }

    /** What our active, owned cars are expected to earn together in a month. */
    public function fleetMonthly(int $year, int $month): float
    {
        return round(array_sum(array_map(
            fn (int $id): float => $this->monthly($id, $year, $month),
            $this->activeOwned(),
        )), 3);
    }

    /**
     * How many of our active, owned cars carry a target for a year.
     *
     * @return array{cars: int, fleet: int}
     */
    public function coverage(int $year): array
    {
        $ids = $this->activeOwned();

        return [
            'cars' => count(array_filter($ids, fn (int $id): bool => $this->yearly($id, $year) > 0.0)),
            'fleet' => count($ids),
        ];
    }

    /**
     * Type a car's target for a year. Zero is kept as a real answer ("this
     * car has no target"), so it is stored rather than removed.
     */
    public function set(int $carId, int $year, float $amount): void
    {
        if (! Schema::hasTable('rental_vehicle_targets')) {
            return;
        }

        $now = CarbonImmutable::now()->toDateTimeString();
        $amount = round(max(0.0, $amount), 3);

        $updated = DB::table('rental_vehicle_targets')
            ->where('vehicle_id', $carId)
            ->where('year', $year)
            ->update(['amount' => $amount, 'updated_at' => $now]);

        if ($updated === 0 && ! DB::table('rental_vehicle_targets')->where('vehicle_id', $carId)->where('year', $year)->exists()) {
            DB::table('rental_vehicle_targets')->insert([
                'vehicle_id' => $carId,
                'year' => $year,
                'amount' => $amount,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $this->typed = null;
        $this->resolved = [];
    }

    /**
     * @return array{amount: float, source: string, from: float|null, fromYear: int|null}
     */
    private function work(int $carId, int $year): array
    {
        $rows = $this->typed()[$carId] ?? [];

        if ($rows === []) {
            $flat = $this->legacy()[$carId] ?? 0.0;

            return ['amount' => $flat, 'source' => $flat > 0.0 ? 'typed' : 'none', 'from' => null, 'fromYear' => null];
        }

        if (isset($rows[$year])) {
            return ['amount' => $rows[$year], 'source' => $rows[$year] > 0.0 ? 'typed' : 'none', 'from' => null, 'fromYear' => null];
        }

        $first = (int) array_key_first($rows);

        // A year from before any target was typed is read at the first figure,
        // as every report did when a car carried a single number.
        if ($year < $first) {
            return ['amount' => $rows[$first], 'source' => $rows[$first] > 0.0 ? 'typed' : 'none', 'from' => null, 'fromYear' => null];
        }

        $base = $first;
        foreach (array_keys($rows) as $typedYear) {
            if ($typedYear < $year) {
                $base = (int) $typedYear;
            }
        }

        $amount = $rows[$base];
        $out = ['amount' => $amount, 'source' => $amount > 0.0 ? 'typed' : 'none', 'from' => null, 'fromYear' => null];
        $thisYear = (int) CarbonImmutable::now()->year;

        for ($y = $base + 1; $y <= $year; $y++) {
            // A year still running has not been reached or missed yet, so a
            // year after it has nothing to grow from.
            if ($y > $thisYear || $amount <= 0.0) {
                break;
            }

            $reached = $this->earnedInYear($carId, $y - 1) >= $amount;
            $out = [
                'amount' => $reached ? round($amount * (1 + self::RAISE), 3) : $amount,
                'source' => $reached ? 'raised' : 'kept',
                'from' => $amount,
                'fromYear' => $y - 1,
            ];
            $amount = $out['amount'];
        }

        return $out;
    }

    /**
     * @return array<int, array<int, float>>
     */
    private function typed(): array
    {
        if ($this->typed !== null) {
            return $this->typed;
        }

        $this->typed = [];

        if (! Schema::hasTable('rental_vehicle_targets')) {
            return $this->typed;
        }

        foreach (DB::table('rental_vehicle_targets')->orderBy('year')->get(['vehicle_id', 'year', 'amount']) as $row) {
            $this->typed[(int) $row->vehicle_id][(int) $row->year] = round((float) $row->amount, 3);
        }

        return $this->typed;
    }

    /**
     * @return array<int, float>
     */
    private function legacy(): array
    {
        if ($this->legacy !== null) {
            return $this->legacy;
        }

        $this->legacy = [];

        if (! Schema::hasTable('rental_vehicles') || ! Schema::hasColumn('rental_vehicles', 'yearly_target')) {
            return $this->legacy;
        }

        foreach (DB::table('rental_vehicles')->get(['id', 'monthly_target', 'yearly_target']) as $car) {
            $yearly = (float) $car->yearly_target;
            $this->legacy[(int) $car->id] = $yearly > 0.0 ? round($yearly, 3) : round((float) $car->monthly_target * 12, 3);
        }

        return $this->legacy;
    }

    /**
     * @return array{owned: array<int, true>, outside: array<int, true>, plates: array<string, int>}
     */
    private function cars(): array
    {
        if ($this->cars !== null) {
            return $this->cars;
        }

        $this->cars = ['owned' => [], 'outside' => [], 'plates' => []];

        if (! Schema::hasTable('rental_vehicles')) {
            return $this->cars;
        }

        foreach (DB::table('rental_vehicles')->get(['id', 'plate_no', 'is_outside']) as $car) {
            $this->cars[(bool) $car->is_outside ? 'outside' : 'owned'][(int) $car->id] = true;

            if (is_string($car->plate_no) && $car->plate_no !== '') {
                $this->cars['plates'][$car->plate_no] = (int) $car->id;
            }
        }

        return $this->cars;
    }

    /**
     * @return list<int>
     */
    private function activeOwned(): array
    {
        if (! Schema::hasTable('rental_vehicles')) {
            return [];
        }

        return DB::table('rental_vehicles')
            ->where('active', true)
            ->where('is_outside', false)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * Money by car and month for a year. Car 0 is work booked against no car;
     * car -1 is old-system figures for a plate that is no longer on file.
     *
     * @return array<int, array<int, float>>
     */
    private function earnedByCar(int $year): array
    {
        if (isset($this->earned[$year])) {
            return $this->earned[$year];
        }

        $start = CarbonImmutable::create($year, 1, 1)->startOfDay();
        $window = [$start->toDateString(), $start->endOfYear()->toDateTimeString()];
        $out = [];

        $add = static function (int $car, int $month, float $amount) use (&$out): void {
            $out[$car][$month] = ($out[$car][$month] ?? 0.0) + $amount;
        };

        if (Schema::hasTable('rental_orders')) {
            $orders = DB::table('rental_orders')
                ->where('state', '!=', 'cancelled')
                ->whereBetween('start_date', $window)
                ->get(['vehicle_id', 'start_date', 'total', 'outside_cost']);

            foreach ($orders as $order) {
                $add(
                    (int) ($order->vehicle_id ?? 0),
                    (int) CarbonImmutable::parse((string) $order->start_date)->format('n'),
                    (float) $order->total - (float) $order->outside_cost,
                );
            }
        }

        if (Schema::hasTable('limo_legs') && Schema::hasTable('limo_bookings')) {
            $legs = DB::table('limo_legs as l')
                ->join('limo_bookings as b', 'b.id', '=', 'l.legable_id')
                ->where('l.legable_type', 'Modules\\Limousine\\Models\\LimoBooking')
                ->where('b.status', '!=', 'cancelled')
                ->where(static fn ($q) => $q->whereNull('l.status')->orWhere('l.status', '!=', 'cancelled'))
                ->whereNotNull('l.car_id')
                ->whereBetween('l.start_at', $window)
                ->get(['l.car_id', 'l.start_at', 'l.net_amount']);

            foreach ($legs as $leg) {
                $add(
                    (int) $leg->car_id,
                    (int) CarbonImmutable::parse((string) $leg->start_at)->format('n'),
                    (float) $leg->net_amount,
                );
            }
        }

        if (Schema::hasTable('rental_revenue_history')) {
            $plates = $this->cars()['plates'];

            foreach (DB::table('rental_revenue_history')->where('year', $year)->get(['plate_no', 'month', 'amount']) as $row) {
                $month = (int) $row->month;
                if ($month < 1 || $month > 12) {
                    continue;
                }
                $add($plates[(string) ($row->plate_no ?? '')] ?? -1, $month, (float) $row->amount);
            }
        }

        return $this->earned[$year] = $out;
    }
}
