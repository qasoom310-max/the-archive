<?php

declare(strict_types=1);

namespace App\Erp\Targets;

use App\Erp\Settings\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The owner's revenue goals per app, and how the year is tracking against them.
 *
 * Monthly and yearly are stored INDEPENDENTLY rather than yearly = monthly x 12,
 * because a transport business is seasonal - Eid and the F1 weekend are not a
 * twelfth of the year each, so a derived annual figure would be wrong in both
 * directions.
 *
 * Targets live in this database's own settings, so each business keeps its own.
 */
final class RevenueTargets
{
    /** @var list<string> */
    public const APPS = ['rental', 'limousine'];

    /**
     * How each app counts money it has actually collected.
     *
     * These MIRROR the revenue figure each app's own dashboard already shows -
     * notably Rental's is NET of what is paid to outside vendors. A target box
     * measuring a different number from the revenue card beside it would be
     * worse than no target at all.
     *
     * app => [table, date column, SQL sum, paid-status column]
     *
     * @var array<string, array{table: string, column: string, sum: string, paid: string}>
     */
    private const REVENUE = [
        'rental' => [
            'table' => 'rental_orders',
            'column' => 'start_date',
            'sum' => 'COALESCE(SUM(total - outside_cost), 0)',
            'paid' => 'payment_status',
        ],
        'limousine' => [
            'table' => 'limo_bookings',
            'column' => 'pickup_at',
            'sum' => 'COALESCE(SUM(fare), 0)',
            'paid' => 'payment_status',
        ],
    ];

    public function monthly(string $app): ?float
    {
        return $this->read($app, 'monthly');
    }

    public function yearly(string $app): ?float
    {
        return $this->read($app, 'yearly');
    }

    /** A null clears that target - "not set" is a real state, distinct from zero. */
    public function set(string $app, ?float $monthly, ?float $yearly): void
    {
        if (! in_array($app, self::APPS, true)) {
            return;
        }

        Setting::set("targets.{$app}.monthly", $monthly === null ? '' : (string) round($monthly, 3));
        Setting::set("targets.{$app}.yearly", $yearly === null ? '' : (string) round($yearly, 3));
    }

    /**
     * Everything the two dashboard boxes need, for the month and the year that
     * contain $now.
     *
     * @return array{
     *     month: array{earned: float, target: float|null, pct: int|null, remaining: float, label: string, unpaid: float, source: string|null},
     *     year: array{earned: float, target: float|null, pct: int|null, remaining: float, label: string, unpaid: float, source: string|null},
     *     fleet: array{total: float, cars: int}|null,
     *     outstanding: float,
     *     pace: int|null
     * }
     */
    public function progress(string $app, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        // What the owner typed always wins. Where nothing is typed, Rent A Car
        // can answer the question from the cars themselves rather than showing
        // no target at all.
        $fleet = $this->fleet($app);
        $typedMonthly = $this->monthly($app);
        $typedYearly = $this->yearly($app);

        $monthlyTarget = $typedMonthly ?? ($fleet['total'] ?? null);
        $yearlyTarget = $typedYearly ?? ($fleet === null ? null : round($fleet['total'] * 12, 3));

        return [
            'month' => $this->box(
                $this->earned($app, $now->startOfMonth(), $now->endOfMonth()),
                $monthlyTarget,
                $now->isoFormat('MMMM YYYY'),
                $this->outstanding($app, $now->startOfMonth(), $now->endOfMonth()),
                $typedMonthly !== null ? 'typed' : ($fleet === null ? null : 'fleet'),
            ),
            'year' => $this->box(
                $yearEarned = $this->earned($app, $now->startOfYear(), $now->endOfYear()),
                $yearlyTarget,
                $now->format('Y'),
                $this->outstanding($app, $now->startOfYear(), $now->endOfYear()),
                // A derived YEAR is only ever an estimate. Twelve equal months
                // is not how this trade runs - Eid and the F1 weekend are not a
                // twelfth each - so it is labelled as a guess to be replaced,
                // never presented as the owner's own number.
                $typedYearly !== null ? 'typed' : ($fleet === null ? null : 'fleet_estimate'),
            ),
            // Where the target came from, so the boxes can show their working.
            'fleet' => $fleet,
            // Every unpaid job on the books, to sit beside all-time revenue.
            'outstanding' => $this->outstanding($app),
            // Is the year on schedule? 100 means exactly where it should be by
            // TODAY, not by December - measuring a part-year against a whole-year
            // target reads as failure every month until the last one.
            'pace' => $this->pace($yearEarned, $yearlyTarget, $now),
        ];
    }

    /** Collected revenue for one app between two dates, counted its own way. */
    public function earned(string $app, CarbonImmutable $from, CarbonImmutable $to): float
    {
        $spec = self::REVENUE[$app] ?? null;

        if ($spec === null || ! Schema::hasTable($spec['table'])) {
            return 0.0;
        }

        $value = DB::table($spec['table'])
            ->where($spec['paid'], 'paid')
            ->whereBetween($spec['column'], self::windowBounds($from, $to))
            ->selectRaw($spec['sum'].' as total')
            ->value('total');

        return round((float) $value, 3);
    }

    /**
     * @return array{earned: float, target: float|null, pct: int|null, remaining: float, label: string, unpaid: float, source: string|null}
     */
    private function box(float $earned, ?float $target, string $label, float $unpaid, ?string $source = null): array
    {
        return [
            'earned' => $earned,
            'target' => $target,
            // 'typed' | 'fleet' | 'fleet_estimate' | null - the box shows its
            // working, so a number nobody remembers setting can be traced.
            'source' => $source,
            // Attainment is COLLECTED money only. Counting what is merely
            // invoiced would let a box read "target met" on a month whose
            // customers have not paid a fils of it.
            'pct' => $target !== null && $target > 0 ? (int) round($earned / $target * 100) : null,
            'remaining' => $target === null ? 0.0 : round(max(0.0, $target - $earned), 3),
            'label' => $label,
            'unpaid' => $unpaid,
        ];
    }

    /**
     * Money already earned in this window but not yet collected.
     *
     * Shown beside each figure because it changes what the figure means: a
     * month at 60% of target with a big unpaid pile is a collection problem,
     * while the same 60% with nothing owed is a sales one.
     *
     * The two businesses genuinely account for it differently - a rental order
     * carries a settled `balance` column, while a booking owes whatever its
     * fare exceeds the advance taken - so both rules are spelled out here
     * rather than hidden behind one expression that suits neither.
     *
     * Passing no window totals every unpaid job on the books.
     */
    public function outstanding(string $app, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): float
    {
        $spec = self::REVENUE[$app] ?? null;

        if ($spec === null || ! Schema::hasTable($spec['table'])) {
            return 0.0;
        }

        $query = DB::table($spec['table']);

        // Status values are written as literals rather than the modules' own
        // constants: this is core code and the module may not be installed.
        if ($app === 'rental') {
            // Mirrors the dashboard's own unpaid card - a live or closed order
            // that is unpaid or part-paid, and the balance it still owes.
            $query->whereIn('payment_status', ['unpaid', 'partial'])
                ->whereIn('state', ['active', 'closed'])
                ->selectRaw('COALESCE(SUM(balance), 0) as total');
        } else {
            // A booking has no balance column: it owes its fare less whatever
            // advance was taken. CASE rather than GREATEST/MAX, which are
            // spelled differently on MySQL and SQLite, and floored per row so
            // an overpaid booking cannot cancel out another's debt.
            $query->where('payment_status', 'unpaid')
                ->where('status', '!=', 'cancelled')
                ->selectRaw('COALESCE(SUM(CASE WHEN fare - advance > 0 THEN fare - advance ELSE 0 END), 0) as total');
        }

        if ($from !== null && $to !== null) {
            $query->whereBetween($spec['column'], [$from->toDateString(), $to->endOfDay()->toDateTimeString()]);
        }

        return round((float) $query->value('total'), 3);
    }

    /**
     * Attainment against the share of the year that has actually elapsed. A year
     * 40% gone with 40% of the target banked is at 100 - on pace.
     */
    private function pace(float $earned, ?float $target, CarbonImmutable $now): ?int
    {
        if ($target === null || $target <= 0) {
            return null;
        }

        $daysInYear = (int) $now->startOfYear()->diffInDays($now->endOfYear()) + 1;
        $elapsed = (int) $now->startOfYear()->diffInDays($now) + 1;
        $expected = $target * ($elapsed / max(1, $daysInYear));

        return $expected <= 0.0 ? null : (int) round($earned / $expected * 100);
    }

    /**
     * The bounds to compare a date column against, for any query in this feature.
     *
     * The same column reads back two different ways, so the bounds have to suit
     * both. rental_orders.start_date is declared DATE, but Eloquent's date cast
     * writes "2026-09-01 00:00:00" into SQLite while MySQL stores the bare
     * "2026-09-01" - and SQLite compares either as a plain string.
     *
     * A date lower bound with a datetime upper bound is correct for both:
     * "2026-09-01 00:00:00" and "2026-09-01" each sort at or after
     * "2026-09-01", and both month-end forms sort before "...23:59:59".
     * Bounding both ends alike drops a whole day at one end or the other, which
     * is exactly how a figure quietly starts under-reporting. Every query in
     * this feature goes through here so that rule is decided once.
     *
     * @return array{0: string, 1: string}
     */
    public static function windowBounds(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [$from->toDateString(), $to->endOfDay()->toDateTimeString()];
    }

    /**
     * What the fleet is supposed to earn in a month, from the cars themselves.
     *
     * Rent A Car already carries a `monthly_target` on every vehicle, set car by
     * car on the car page and reported on the Reports > Targets tab. Adding them
     * up answers "where did that number come from" without the owner retyping a
     * total that would then drift from the cars it came from.
     *
     * Limousine has no vehicle register, so it has no fleet figure and its
     * targets are always typed by hand.
     *
     * @return array{total: float, cars: int}|null
     */
    public function fleet(string $app): ?array
    {
        if ($app !== 'rental' || ! Schema::hasTable('rental_vehicles')) {
            return null;
        }

        // Owned and active only, exactly as the Reports targets tab counts them:
        // a car we rent in from outside earns for its owner, not for us, and a
        // retired car should not still be carrying a target.
        $row = DB::table('rental_vehicles')
            ->where('active', true)
            ->where('is_outside', false)
            ->selectRaw('COALESCE(SUM(monthly_target), 0) as total, COUNT(*) as cars')
            ->first();

        if ($row === null) {
            return null;
        }

        $total = round((float) $row->total, 3);

        // No car carries a target yet, so there is nothing to derive from.
        return $total > 0.0 ? ['total' => $total, 'cars' => (int) $row->cars] : null;
    }

    private function read(string $app, string $key): ?float
    {
        if (! in_array($app, self::APPS, true)) {
            return null;
        }

        $raw = Setting::get("targets.{$app}.{$key}");

        // Blank and absent both mean "no target" - a target of nothing is not the
        // same as a target of zero, and nobody sets zero on purpose.
        if (! is_numeric($raw)) {
            return null;
        }

        $value = (float) $raw;

        return $value > 0.0 ? $value : null;
    }
}
