<?php

declare(strict_types=1);

namespace Modules\Limousine\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where the limousine desk's money comes from, and whether it is holding up.
 *
 * Rent A Car can be judged car by car because every hire names a car. This
 * business cannot: the import never recorded one, so a per-car page here would
 * be a single row reading "not recorded" - which is exactly the mistake the
 * first revenue breakdown made and the reason this class exists.
 *
 * So it reports on the three units a chauffeur business actually turns on:
 *
 *  - the DRIVER, who is the productive unit the way a car is in rental;
 *  - the ROUTE, because an airport run and a city hop are different businesses
 *    wearing the same uniform;
 *  - the HOUR, because this trade is a staffing problem before it is anything
 *    else.
 *
 * Every section reports itself as unavailable rather than empty when the data
 * behind it was never captured, so a blank section is a statement about the
 * records rather than a bug to go hunting for.
 */
final class LimoPerformance
{
    /** Rows before the tail is folded away. */
    private const TOP = 12;

    /** Hour bands, chosen to match how shifts are actually worked. */
    private const BANDS = [
        'early' => [6, 10],
        'day' => [10, 16],
        'evening' => [16, 22],
        'night' => [22, 6],
    ];

    public function __construct(private readonly int $year) {}

    /**
     * @return array{
     *     year: int,
     *     summary: array<string, mixed>,
     *     drivers: array{rows: list<array<string, mixed>>, available: bool, unnamed: int},
     *     routes: array{rows: list<array<string, mixed>>, available: bool, unnamed: int},
     *     services: list<array<string, mixed>>,
     *     demand: array{grid: array<string, array<int, array{trips: int, earned: float}>>, busiest: string|null, bestPaying: string|null}
     * }
     */
    public function report(): array
    {
        if (! Schema::hasTable('limo_legs') || ! Schema::hasTable('limo_bookings')) {
            return $this->empty();
        }

        $trips = $this->trips($this->year);
        $lastYear = $this->trips($this->year - 1);

        return [
            'year' => $this->year,
            'summary' => $this->summary($trips, $lastYear),
            'drivers' => $this->drivers($trips),
            'routes' => $this->routes($trips, $lastYear),
            'services' => $this->services($trips),
            'demand' => $this->demand($trips),
        ];
    }

    /**
     * One row per TRIP - a leg, not a booking. A driver drives a leg, a route
     * is a leg and an hour belongs to a leg, so the leg is the only grain on
     * which any of these three questions can be asked.
     *
     * @return list<array{paid: bool, amount: float, driver: string, from: string, to: string, service: string, at: CarbonImmutable, driverId: int|null}>
     */
    private function trips(int $year): array
    {
        $start = CarbonImmutable::create($year, 1, 1)->startOfDay();
        $end = $start->endOfYear();

        $rows = DB::table('limo_legs as l')
            ->join('limo_bookings as b', 'b.id', '=', 'l.legable_id')
            // Legs are shared with quotations, so the type filter is what keeps
            // a quote that was never taken out of the takings.
            ->where('l.legable_type', 'Modules\\Limousine\\Models\\LimoBooking')
            ->where('b.status', '!=', 'cancelled')
            ->whereNotNull('l.start_at')
            ->whereBetween('l.start_at', [$start->toDateString(), $end->endOfDay()->toDateTimeString()])
            ->get([
                'l.driver', 'l.driver_id', 'l.from_location', 'l.to_location',
                'l.service_type', 'l.start_at', 'l.net_amount', 'b.payment_status',
            ]);

        $out = [];

        foreach ($rows as $row) {
            $out[] = [
                'paid' => $row->payment_status === 'paid',
                'amount' => round((float) $row->net_amount, 3),
                'driver' => $this->clean((string) ($row->driver ?? '')),
                'driverId' => $row->driver_id === null ? null : (int) $row->driver_id,
                'from' => $this->clean((string) ($row->from_location ?? '')),
                'to' => $this->clean((string) ($row->to_location ?? '')),
                'service' => (string) $row->service_type,
                'at' => CarbonImmutable::parse((string) $row->start_at),
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $trips
     * @param  list<array<string, mixed>>  $lastYear
     * @return array<string, mixed>
     */
    private function summary(array $trips, array $lastYear): array
    {
        $paid = array_values(array_filter($trips, static fn (array $t): bool => $t['paid'] === true));
        $earned = round(array_sum(array_column($paid, 'amount')), 3);
        $unpaid = round(array_sum(array_column(
            array_filter($trips, static fn (array $t): bool => $t['paid'] === false),
            'amount',
        )), 3);

        $avg = $paid === [] ? 0.0 : round($earned / count($paid), 3);
        $lastPaid = array_values(array_filter($lastYear, static fn (array $t): bool => $t['paid'] === true));
        $lastAvg = $lastPaid === []
            ? 0.0
            : round(array_sum(array_column($lastPaid, 'amount')) / count($lastPaid), 3);

        return [
            'trips' => count($trips),
            'paidTrips' => count($paid),
            'earned' => $earned,
            'unpaid' => $unpaid,
            'avgFare' => $avg,
            'lastAvgFare' => $lastAvg,
            // The number that says whether the desk is quietly discounting
            // itself into trouble while the trip count still looks healthy.
            'fareShift' => $lastAvg > 0.0 ? (int) round(($avg - $lastAvg) / $lastAvg * 100) : null,
        ];
    }

    /**
     * The driver league, and what each has been advanced against it.
     *
     * Historic trips name a driver in text rather than pointing at a record, so
     * rows are keyed by name. Petty-cash advances point at a driver id, so they
     * are matched back through the driver register and simply do not appear for
     * a name that was never a record.
     *
     * @param  list<array<string, mixed>>  $trips
     * @return array{rows: list<array<string, mixed>>, available: bool, unnamed: int}
     */
    private function drivers(array $trips): array
    {
        $totals = [];
        $unnamed = 0;
        $ids = [];

        foreach ($trips as $trip) {
            if ($trip['driver'] === '') {
                $unnamed++;

                continue;
            }

            $key = $trip['driver'];
            $totals[$key] ??= ['name' => $key, 'trips' => 0, 'earned' => 0.0, 'unpaid' => 0.0, 'advanced' => 0.0];
            $totals[$key]['trips']++;

            if ($trip['paid'] === true) {
                $totals[$key]['earned'] = round($totals[$key]['earned'] + $trip['amount'], 3);
            } else {
                $totals[$key]['unpaid'] = round($totals[$key]['unpaid'] + $trip['amount'], 3);
            }

            if ($trip['driverId'] !== null) {
                $ids[$trip['driverId']] = $key;
            }
        }

        foreach ($this->advances($ids) as $name => $amount) {
            if (isset($totals[$name])) {
                $totals[$name]['advanced'] = $amount;
            }
        }

        $rows = array_values($totals);
        usort($rows, static fn (array $a, array $b): int => $b['earned'] <=> $a['earned']);

        $fleetAvg = $rows === []
            ? 0.0
            : array_sum(array_column($rows, 'earned')) / max(1, array_sum(array_column($rows, 'trips')));

        foreach ($rows as $i => $row) {
            $avg = $row['trips'] > 0 ? round($row['earned'] / $row['trips'], 3) : 0.0;
            $rows[$i]['avgFare'] = $avg;
            // Busy at a low fare and quiet at a high one are different problems
            // wearing the same low total, exactly as with a car.
            $rows[$i]['verdict'] = match (true) {
                // Nothing to compare against, so no judgement is offered.
                $fleetAvg <= 0.0 => 'steady',
                $avg >= $fleetAvg * 1.15 => 'premium',
                $avg <= $fleetAvg * 0.85 => 'cheap',
                default => 'steady',
            };
        }

        return [
            'rows' => array_slice($rows, 0, self::TOP),
            'available' => $rows !== [],
            'unnamed' => $unnamed,
        ];
    }

    /**
     * Money handed to drivers as a float in the year, by driver name.
     *
     * @param  array<int, string>  $ids  driver id => the name used on trips
     * @return array<string, float>
     */
    private function advances(array $ids): array
    {
        if ($ids === [] || ! Schema::hasTable('limo_petty_advances')) {
            return [];
        }

        $start = CarbonImmutable::create($this->year, 1, 1);

        $rows = DB::table('limo_petty_advances')
            ->whereIn('driver_id', array_keys($ids))
            ->whereBetween('date', [$start->toDateString(), $start->endOfYear()->toDateString()])
            ->selectRaw('driver_id, COALESCE(SUM(amount), 0) as advanced')
            ->groupBy('driver_id')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $name = $ids[(int) $row->driver_id] ?? null;

            if ($name !== null) {
                $out[$name] = round(($out[$name] ?? 0.0) + (float) $row->advanced, 3);
            }
        }

        return $out;
    }

    /**
     * Which journeys earn. An airport run and a city hop are different
     * businesses, and a falling average on one of them is invisible in a total.
     *
     * @param  list<array<string, mixed>>  $trips
     * @param  list<array<string, mixed>>  $lastYear
     * @return array{rows: list<array<string, mixed>>, available: bool, unnamed: int}
     */
    private function routes(array $trips, array $lastYear): array
    {
        $totals = $this->routeTotals($trips);
        $before = $this->routeTotals($lastYear);
        $unnamed = 0;

        foreach ($trips as $trip) {
            if ($trip['from'] === '' && $trip['to'] === '') {
                $unnamed++;
            }
        }

        $rows = [];

        foreach ($totals as $label => $bucket) {
            $avg = $bucket['paidTrips'] > 0 ? round($bucket['earned'] / $bucket['paidTrips'], 3) : 0.0;
            $wasAvg = ($before[$label]['paidTrips'] ?? 0) > 0
                ? round($before[$label]['earned'] / $before[$label]['paidTrips'], 3)
                : 0.0;

            $rows[] = [
                'label' => (string) $label,
                'trips' => $bucket['trips'],
                'earned' => $bucket['earned'],
                'avgFare' => $avg,
                // Against the SAME route a year ago, not against the fleet
                // average, so a genuinely cheap route is not called a decline.
                'shift' => $wasAvg > 0.0 ? (int) round(($avg - $wasAvg) / $wasAvg * 100) : null,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $b['earned'] <=> $a['earned']);

        return [
            'rows' => array_slice($rows, 0, self::TOP),
            'available' => $rows !== [],
            'unnamed' => $unnamed,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $trips
     * @return array<string, array{trips: int, paidTrips: int, earned: float}>
     */
    private function routeTotals(array $trips): array
    {
        $totals = [];

        foreach ($trips as $trip) {
            if ($trip['from'] === '' && $trip['to'] === '') {
                continue;
            }

            $label = $trip['from'] === ''
                ? __('to :place', ['place' => $trip['to']])
                : ($trip['to'] === ''
                    ? __('from :place', ['place' => $trip['from']])
                    : $trip['from'].' → '.$trip['to']);

            $totals[$label] ??= ['trips' => 0, 'paidTrips' => 0, 'earned' => 0.0];
            $totals[$label]['trips']++;

            if ($trip['paid'] === true) {
                $totals[$label]['paidTrips']++;
                $totals[$label]['earned'] = round($totals[$label]['earned'] + $trip['amount'], 3);
            }
        }

        return $totals;
    }

    /**
     * @param  list<array<string, mixed>>  $trips
     * @return list<array<string, mixed>>
     */
    private function services(array $trips): array
    {
        $totals = [];

        foreach ($trips as $trip) {
            $label = match ($trip['service']) {
                'transfer' => __('Pick & drop (transfer)'),
                'chauffeur' => __('Chauffeur (hours)'),
                default => $trip['service'] === '' ? __('Not recorded') : $trip['service'],
            };

            $totals[$label] ??= ['label' => $label, 'trips' => 0, 'paidTrips' => 0, 'earned' => 0.0];
            $totals[$label]['trips']++;

            if ($trip['paid'] === true) {
                $totals[$label]['paidTrips']++;
                $totals[$label]['earned'] = round($totals[$label]['earned'] + $trip['amount'], 3);
            }
        }

        $rows = array_values($totals);

        foreach ($rows as $i => $row) {
            $rows[$i]['avgFare'] = $row['paidTrips'] > 0 ? round($row['earned'] / $row['paidTrips'], 3) : 0.0;
        }

        usort($rows, static fn (array $a, array $b): int => $b['earned'] <=> $a['earned']);

        return $rows;
    }

    /**
     * When the work actually lands, and what it pays when it does.
     *
     * Volume alone would send everyone to the busiest hour. The average fare
     * beside it is what says whether the busiest hour is also the one worth
     * staffing - a quiet late-night band at double the fare often is.
     *
     * @param  list<array<string, mixed>>  $trips
     * @return array{grid: array<string, array<int, array{trips: int, earned: float}>>, busiest: string|null, bestPaying: string|null}
     */
    private function demand(array $trips): array
    {
        $grid = [];

        foreach (array_keys(self::BANDS) as $band) {
            $grid[$band] = [];
            for ($dow = 0; $dow < 7; $dow++) {
                $grid[$band][$dow] = ['trips' => 0, 'earned' => 0.0];
            }
        }

        $cells = [];

        foreach ($trips as $trip) {
            $band = $this->band((int) $trip['at']->format('G'));
            $dow = (int) $trip['at']->format('w');

            $grid[$band][$dow]['trips']++;

            if ($trip['paid'] === true) {
                $grid[$band][$dow]['earned'] = round($grid[$band][$dow]['earned'] + $trip['amount'], 3);
                $cells[$band.'|'.$dow]['paid'] = ($cells[$band.'|'.$dow]['paid'] ?? 0) + 1;
                $cells[$band.'|'.$dow]['earned'] = ($cells[$band.'|'.$dow]['earned'] ?? 0.0) + $trip['amount'];
            }
        }

        $busiest = null;
        $bestPaying = null;
        $mostTrips = 0;
        $bestAvg = 0.0;

        foreach ($grid as $band => $days) {
            foreach ($days as $dow => $cell) {
                if ($cell['trips'] > $mostTrips) {
                    $mostTrips = $cell['trips'];
                    $busiest = $band.'|'.$dow;
                }

                $paid = $cells[$band.'|'.$dow]['paid'] ?? 0;

                // A single lucky trip is not a pattern worth staffing for.
                if ($paid >= 5) {
                    $avg = $cells[$band.'|'.$dow]['earned'] / $paid;

                    if ($avg > $bestAvg) {
                        $bestAvg = $avg;
                        $bestPaying = $band.'|'.$dow;
                    }
                }
            }
        }

        return ['grid' => $grid, 'busiest' => $busiest, 'bestPaying' => $bestPaying];
    }

    private function band(int $hour): string
    {
        foreach (self::BANDS as $name => [$from, $to]) {
            // The night band wraps past midnight, so it is the one range that
            // cannot be tested with a plain between.
            if ($from < $to ? ($hour >= $from && $hour < $to) : ($hour >= $from || $hour < $to)) {
                return $name;
            }
        }

        return 'night';
    }

    /** Trims and case-folds so "AIRPORT" and " Airport " are one place. */
    private function clean(string $value): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return $value === '' ? '' : mb_convert_case($value, MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * @return array{year: int, summary: array<string, mixed>, drivers: array{rows: list<array<string, mixed>>, available: bool, unnamed: int}, routes: array{rows: list<array<string, mixed>>, available: bool, unnamed: int}, services: list<array<string, mixed>>, demand: array{grid: array<string, array<int, array{trips: int, earned: float}>>, busiest: string|null, bestPaying: string|null}}
     */
    private function empty(): array
    {
        return [
            'year' => $this->year,
            'summary' => ['trips' => 0, 'paidTrips' => 0, 'earned' => 0.0, 'unpaid' => 0.0, 'avgFare' => 0.0, 'lastAvgFare' => 0.0, 'fareShift' => null],
            'drivers' => ['rows' => [], 'available' => false, 'unnamed' => 0],
            'routes' => ['rows' => [], 'available' => false, 'unnamed' => 0],
            'services' => [],
            'demand' => $this->demand([]),
        ];
    }
}
