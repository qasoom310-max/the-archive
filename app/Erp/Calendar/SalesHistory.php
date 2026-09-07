<?php

declare(strict_types=1);

namespace App\Erp\Calendar;

use App\Erp\Business\Features;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What this database actually sold, per day, across whichever apps it runs.
 *
 * Reads the three revenue sources directly (rental orders by pick-up date,
 * limousine bookings by pick-up time, POS sales by order time) and sums them
 * per calendar day. Every module is installed in every database, so the
 * tables always exist — the business type is what decides which of them
 * count here, the same gate the app bar uses.
 */
final class SalesHistory
{
    public const SOURCES = ['rental', 'limousine', 'pos'];

    /**
     * source → [module, table, date column, amount column, state column, cancelled value]
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}>
     */
    private const MAP = [
        'rental' => ['rental', 'rental_orders', 'start_date', 'total', 'state', 'cancelled'],
        'limousine' => ['limousine', 'limo_bookings', 'pickup_at', 'amount', 'status', 'cancelled'],
        'pos' => ['pos', 'pos_orders', 'ordered_at', 'total', 'state', 'done'],
    ];

    /**
     * The revenue sources this database runs.
     *
     * @return list<string>
     */
    public function sources(): array
    {
        $out = [];

        foreach (self::MAP as $source => [$module, $table]) {
            if (Schema::hasTable($table) && Features::moduleAllowed($module)) {
                $out[] = $source;
            }
        }

        return $out;
    }

    /**
     * Revenue per day for every day in [$from, $to], zeros included.
     *
     * @return array<string, array{rental: float, limousine: float, pos: float, total: float, count: int}>
     */
    public function daily(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $days = [];
        for ($cursor = $from->startOfDay(); $cursor->lessThanOrEqualTo($to); $cursor = $cursor->addDay()) {
            $days[$cursor->toDateString()] = ['rental' => 0.0, 'limousine' => 0.0, 'pos' => 0.0, 'total' => 0.0, 'count' => 0];
        }

        foreach ($this->sources() as $source) {
            [, $table, $dateColumn, $amountColumn] = self::MAP[$source];

            $rows = $this->query($source)
                ->whereBetween($dateColumn, [$from->startOfDay()->toDateTimeString(), $to->endOfDay()->toDateTimeString()])
                ->get([$dateColumn, $amountColumn]);

            foreach ($rows as $row) {
                $day = substr((string) $row->{$dateColumn}, 0, 10);
                if (! isset($days[$day])) {
                    continue;
                }

                $amount = round((float) $row->{$amountColumn}, 3);
                $days[$day][$source] = round($days[$day][$source] + $amount, 3);
                $days[$day]['total'] = round($days[$day]['total'] + $amount, 3);
                $days[$day]['count']++;
            }
        }

        return $days;
    }

    public function total(CarbonImmutable $from, CarbonImmutable $to): float
    {
        $sum = 0.0;
        foreach ($this->daily($from, $to) as $day) {
            $sum += $day['total'];
        }

        return round($sum, 3);
    }

    /**
     * The earliest sale on record — days before it are "no data", not "no
     * demand", and the calendar greys them out rather than reading them as
     * a dead season.
     */
    public function firstRecordDate(): ?CarbonImmutable
    {
        $first = null;

        foreach ($this->sources() as $source) {
            $dateColumn = self::MAP[$source][2];
            $min = $this->query($source)->whereNotNull($dateColumn)->min($dateColumn);
            if ($min === null || $min === '') {
                continue;
            }

            $date = CarbonImmutable::parse((string) $min)->startOfDay();
            if ($first === null || $date->lessThan($first)) {
                $first = $date;
            }
        }

        return $first;
    }

    /**
     * Rows that count as revenue: cancelled orders never do, and a POS order
     * only once it is Done.
     */
    private function query(string $source): \Illuminate\Database\Query\Builder
    {
        [, $table, , , $stateColumn, $stateValue] = self::MAP[$source];

        $query = DB::table($table);

        return $source === 'pos'
            ? $query->where($stateColumn, $stateValue)
            : $query->where($stateColumn, '!=', $stateValue);
    }
}
