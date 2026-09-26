<?php

declare(strict_types=1);

namespace App\Erp\Targets;

use App\Erp\Settings\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The owner's revenue goals per app, and the three numbers that say how the
 * business is really doing against them: work done, money paid, money owed.
 *
 * The first version counted only jobs PAID IN FULL, dated by when the job
 * started. That quietly threw away every part-payment: a 500 BD hire with a
 * 300 BD deposit counted as nothing collected and nothing earned, so a busy
 * month read as zero and the owner, rightly, stopped trusting the page. Every
 * figure now starts from the work that actually ran, and says separately how
 * much of it has been paid and how much is still owed.
 *
 * Monthly and yearly targets are stored INDEPENDENTLY rather than yearly =
 * monthly x 12, because this trade is seasonal. Nothing here invents a yearly
 * target from a monthly one: a year nobody set is a year with no target.
 *
 * Targets live in this database's own settings, so each business keeps its own.
 */
final class RevenueTargets
{
    /** @var list<string> */
    public const APPS = ['rental', 'limousine'];

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
     * Everything the money band needs, for the month and the year that contain
     * $now.
     *
     * @return array{
     *     month: array{earned: float, paid: float, unpaid: float, vendors: float, target: float|null, pct: int|null, remaining: float, label: string, source: string|null},
     *     year: array{earned: float, paid: float, unpaid: float, vendors: float, target: float|null, pct: int|null, remaining: float, label: string, source: string|null},
     *     fleet: array{total: float, cars: int, fleet: int}|null,
     *     collected: float,
     *     owedBefore: float,
     *     outstanding: float,
     *     pace: int|null
     * }
     */
    public function progress(string $app, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        $month = $this->work($app, $now->startOfMonth(), $now->endOfMonth());
        $year = $this->work($app, $now->startOfYear(), $now->endOfYear());
        $all = $this->work($app);

        $fleet = $this->fleet($app);
        $typedMonthly = $this->monthly($app);
        $typedYearly = $this->yearly($app);

        // A fleet target is only a fleet target when EVERY car carries one.
        // Adding up the one car that has a target and calling it "the fleet"
        // produced a 150 BD month for a 21-car business, and then celebrated
        // beating it. A partial sum is reported as a gap to fill, not used.
        $fleetComplete = $fleet !== null && $fleet['total'] > 0.0 && $fleet['cars'] === $fleet['fleet'];

        $monthlyTarget = $typedMonthly ?? ($fleetComplete ? $fleet['total'] : null);

        return [
            'month' => $this->box(
                $month,
                $monthlyTarget,
                $now->isoFormat('MMMM YYYY'),
                $typedMonthly !== null ? 'typed' : ($fleetComplete ? 'fleet' : null),
            ),
            // Typed only. Twelve equal months is not how this trade runs, and a
            // derived year was exactly what printed "target met" against a
            // number nobody had chosen.
            'year' => $this->box($year, $typedYearly, $now->format('Y'), $typedYearly !== null ? 'typed' : null),
            'fleet' => $fleet,
            // Cash actually received, all time, part-payments included.
            'collected' => $all['paid'],
            // Debt from earlier months, kept apart from this month's so the
            // team can chase the current month without the old pile hiding it.
            'owedBefore' => $this->work($app, null, $now->startOfMonth()->subDay())['unpaid'],
            'outstanding' => $all['unpaid'],
            // Is the year on schedule? 100 means exactly where it should be by
            // TODAY, not by December.
            'pace' => $this->pace($year['earned'], $typedYearly, $now),
        ];
    }

    /**
     * The work that ran in a window and what has happened to the money for it.
     *
     *  - earned:  what the work is worth to US. Rent A Car is net of what is
     *             paid to outside vendors for a rented-in car; Limousine is
     *             the fare. Targets are measured against this.
     *  - paid:    what the customers have actually handed over for it.
     *  - unpaid:  what they still owe for it.
     *  - vendors: the outside vendors' share, which is why paid + unpaid can
     *             come to more than earned on a Rent A Car month.
     *
     * A null bound is open: work(app) is all time, work(app, null, $to) is
     * everything up to $to.
     *
     * @return array{earned: float, paid: float, unpaid: float, vendors: float, jobs: int}
     */
    public function work(string $app, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): array
    {
        return match ($app) {
            'rental' => $this->rentalWork($from, $to),
            'limousine' => $this->limousineWork($from, $to),
            default => $this->nothing(),
        };
    }

    /** Work done in a window, counted the way the target is measured. */
    public function earned(string $app, CarbonImmutable $from, CarbonImmutable $to): float
    {
        return $this->work($app, $from, $to)['earned'];
    }

    /** What is still owed for the work in a window. No window: everything owed. */
    public function outstanding(string $app, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): float
    {
        return $this->work($app, $from, $to)['unpaid'];
    }

    /**
     * Rent A Car. A hire that is live or closed is work done, whatever its
     * payment state; a draft is a reservation that has not run, and a cancelled
     * one never will.
     *
     * Money reaches a rental order by two roads that never meet. Money taken at
     * the counter lands on the order's own advance. Money paid against an
     * invoice lands on the invoice, and the order only hears about it once that
     * invoice is paid IN FULL - at which point it is flagged paid but its own
     * balance is never touched. So neither the order's balance nor its flag is
     * enough on its own: an order is paid what is on its advance plus every
     * receipt against an invoice raised from it, capped at its total, or all of
     * it once it is flagged paid.
     *
     * No double count on imported history: the import put every dinar received
     * on the order's advance and linked none of its invoices to an order.
     *
     * @return array{earned: float, paid: float, unpaid: float, vendors: float, jobs: int}
     */
    private function rentalWork(?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        if (! Schema::hasTable('rental_orders')) {
            return $this->nothing();
        }

        $orders = $this->window(
            DB::table('rental_orders')->whereIn('state', ['active', 'closed']),
            'start_date', $from, $to,
        )->get(['id', 'total', 'outside_cost', 'advance_amount', 'payment_status']);

        $receipts = [];

        if (Schema::hasTable('rental_receipts') && Schema::hasTable('rental_invoices')) {
            // One grouped query, joined back to the same orders, rather than a
            // list of ids - a whole year of orders is more ids than SQLite will
            // bind in one statement.
            $receipts = $this->window(
                DB::table('rental_receipts as r')
                    ->join('rental_invoices as i', 'i.id', '=', 'r.invoice_id')
                    ->join('rental_orders as o', 'o.id', '=', 'i.order_id')
                    ->whereIn('o.state', ['active', 'closed']),
                'o.start_date', $from, $to,
            )
                ->groupBy('i.order_id')
                ->selectRaw('i.order_id as order_id, COALESCE(SUM(r.amount), 0) as received')
                ->pluck('received', 'order_id')
                ->map(static fn (mixed $v): float => (float) $v)
                ->all();
        }

        $billed = 0.0;
        $paid = 0.0;
        $vendors = 0.0;

        foreach ($orders as $order) {
            $total = (float) $order->total;
            $billed += $total;
            $vendors += (float) $order->outside_cost;

            $paid += $order->payment_status === 'paid'
                ? $total
                : min($total, max(0.0, (float) $order->advance_amount + ($receipts[(int) $order->id] ?? 0.0)));
        }

        return $this->totals($billed, $paid, $vendors, $orders->count());
    }

    /**
     * Limousine. A booking that is not cancelled is work done. Every payment
     * taken through the booking lands on its advance, so the advance is what
     * has been paid - or the whole fare, once the booking is flagged paid
     * (which is how a booking settled through an invoice receipt reads).
     * Floored per booking so an overpaid one cannot cancel out another's debt.
     *
     * @return array{earned: float, paid: float, unpaid: float, vendors: float, jobs: int}
     */
    private function limousineWork(?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        if (! Schema::hasTable('limo_bookings')) {
            return $this->nothing();
        }

        $bookings = $this->window(
            DB::table('limo_bookings')->where('status', '!=', 'cancelled'),
            'pickup_at', $from, $to,
        )->get(['fare', 'advance', 'payment_status']);

        $billed = 0.0;
        $paid = 0.0;

        foreach ($bookings as $booking) {
            $fare = (float) $booking->fare;
            $billed += $fare;
            $paid += $booking->payment_status === 'paid'
                ? $fare
                : min($fare, max(0.0, (float) $booking->advance));
        }

        return $this->totals($billed, $paid, 0.0, $bookings->count());
    }

    /**
     * @return array{earned: float, paid: float, unpaid: float, vendors: float, jobs: int}
     */
    private function totals(float $billed, float $paid, float $vendors, int $jobs): array
    {
        return [
            'earned' => round($billed - $vendors, 3),
            'paid' => round($paid, 3),
            'unpaid' => round(max(0.0, $billed - $paid), 3),
            'vendors' => round($vendors, 3),
            'jobs' => $jobs,
        ];
    }

    /**
     * @return array{earned: float, paid: float, unpaid: float, vendors: float, jobs: int}
     */
    private function nothing(): array
    {
        return ['earned' => 0.0, 'paid' => 0.0, 'unpaid' => 0.0, 'vendors' => 0.0, 'jobs' => 0];
    }

    /**
     * Applies either or both ends of a window, with the same date rule as
     * {@see windowBounds()}.
     */
    private function window(Builder $query, string $column, ?CarbonImmutable $from, ?CarbonImmutable $to): Builder
    {
        if ($from !== null) {
            $query->where($column, '>=', $from->toDateString());
        }

        if ($to !== null) {
            $query->where($column, '<=', $to->endOfDay()->toDateTimeString());
        }

        return $query;
    }

    /**
     * @param  array{earned: float, paid: float, unpaid: float, vendors: float, jobs: int}  $work
     * @return array{earned: float, paid: float, unpaid: float, vendors: float, target: float|null, pct: int|null, remaining: float, label: string, source: string|null}
     */
    private function box(array $work, ?float $target, string $label, ?string $source): array
    {
        return [
            'earned' => $work['earned'],
            'paid' => $work['paid'],
            'unpaid' => $work['unpaid'],
            'vendors' => $work['vendors'],
            'target' => $target,
            // 'typed' | 'fleet' | null - the box shows its working, so a
            // number nobody remembers setting can be traced.
            'source' => $source,
            'pct' => $target !== null && $target > 0 ? (int) round($work['earned'] / $target * 100) : null,
            'remaining' => $target === null ? 0.0 : round(max(0.0, $target - $work['earned']), 3),
            'label' => $label,
        ];
    }

    /**
     * Attainment against the share of the year that has actually elapsed. A year
     * 40% gone with 40% of the target done is at 100 - on pace.
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
     * A date lower bound with a datetime upper bound is correct for both.
     * Bounding both ends alike drops a whole day at one end or the other, which
     * is exactly how a figure quietly starts under-reporting.
     *
     * @return array{0: string, 1: string}
     */
    public static function windowBounds(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [$from->toDateString(), $to->endOfDay()->toDateTimeString()];
    }

    /**
     * What the cars themselves say the fleet should earn in a month, and how
     * many of them actually say it.
     *
     * `cars` counts only the cars that carry a target; `fleet` counts every
     * active owned car. The earlier version reported COUNT(*) as the number of
     * contributing cars, so one car's target read as "added up from 21 cars".
     *
     * Limousine has no vehicle register, so it has no fleet figure.
     *
     * @return array{total: float, cars: int, fleet: int}|null
     */
    public function fleet(string $app): ?array
    {
        if ($app !== 'rental' || ! Schema::hasTable('rental_vehicles')) {
            return null;
        }

        // Owned and active only: a car rented in from outside earns for its
        // owner, and a retired car should not still be carrying a target.
        $row = DB::table('rental_vehicles')
            ->where('active', true)
            ->where('is_outside', false)
            ->selectRaw('COALESCE(SUM(monthly_target), 0) as total, COUNT(*) as fleet, '
                .'COALESCE(SUM(CASE WHEN monthly_target > 0 THEN 1 ELSE 0 END), 0) as cars')
            ->first();

        if ($row === null || (int) $row->fleet === 0) {
            return null;
        }

        return [
            'total' => round((float) $row->total, 3),
            'cars' => (int) $row->cars,
            'fleet' => (int) $row->fleet,
        ];
    }

    private function read(string $app, string $key): ?float
    {
        if (! in_array($app, self::APPS, true)) {
            return null;
        }

        $raw = Setting::get("targets.{$app}.{$key}");

        // Blank and absent both mean "no target".
        if (! is_numeric($raw)) {
            return null;
        }

        $value = (float) $raw;

        return $value > 0.0 ? $value : null;
    }
}
