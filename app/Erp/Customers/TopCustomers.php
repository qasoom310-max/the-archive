<?php

declare(strict_types=1);

namespace App\Erp\Customers;

use App\Erp\Targets\RevenueTargets;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who pays this business the most, and which of them have gone quiet.
 *
 * A plain "biggest customers" list is a trophy cabinet: it names the same
 * people every month and gives nobody anything to do. The useful question is
 * which of the people who already pay us have stopped, and the answer needs
 * each customer judged against THEIR OWN rhythm rather than one cut-off for
 * everybody.
 *
 * A company that hires a car every three weeks and has not called in eight is
 * a real problem. A family that hires once a year and has not called in eight
 * weeks is behaving completely normally. One fixed "quiet for 60 days" rule
 * calls both of them the same thing and is therefore wrong about one of them
 * every time. So each customer's typical gap between jobs is measured from
 * their own history, and "overdue" means overdue for THEM.
 *
 * The median is used rather than the mean: one twelve-month gap in an
 * otherwise fortnightly customer would drag an average far enough to excuse
 * almost any silence.
 */
final class TopCustomers
{
    /**
     * How far back the ranking looks, in whole calendar months including this
     * one.
     *
     * Six, not twelve. Over a year the list filled with people who hired once
     * and were last seen ten months ago - every row reading "too few jobs to
     * know their rhythm" - which is a list of strangers, not a call sheet. Six
     * months is short enough that everyone on it is still a live relationship.
     */
    public const WINDOW_MONTHS = 6;

    /** No customer is chased before this many days, however tight their rhythm. */
    private const GRACE_DAYS = 7;

    /** Past their own gap by this much: worth a call. */
    private const SLIPPING_AT = 1.25;

    /** Past it by this much: treat as lost and win back. */
    private const LOST_AT = 2.5;

    /** Someone with too little history to have a rhythm is judged on this. */
    private const NO_RHYTHM_LOST_DAYS = 180;

    /** Ranked apart, because they are different businesses. */
    private const GROUPS = ['company', 'individual'];

    /** Length of each half of the up/down comparison. */
    private const TREND_DAYS = 90;

    /**
     * app => [jobs table, customer table, date column, amount SQL, customer page]
     *
     * @var array<string, array{jobs: string, customers: string, date: string, amount: string, href: string}>
     */
    private const APPS = [
        'rental' => [
            'jobs' => 'rental_orders',
            'customers' => 'rental_customers',
            'date' => 'start_date',
            // Net of outside vendors, matching every other rental figure here.
            'amount' => '(total - outside_cost)',
            'href' => '/app/rental/customer/',
        ],
        'limousine' => [
            'jobs' => 'limo_bookings',
            'customers' => 'limo_customers',
            'date' => 'pickup_at',
            'amount' => 'fare',
            // The summary page, not the edit form: this is a call sheet, and
            // what the caller wants is the customer's history in front of them.
            'href' => '/app/limousine/customer/',
        ],
    ];

    /**
     * @return array{
     *     months: list<array{key: string, label: string}>,
     *     groups: array<string, array{
     *         rows: list<array<string, mixed>>, total: float, share: int|null,
     *         quiet: int, atRisk: float
     *     }>,
     *     collected: float, windowMonths: int, from: string, to: string
     * }
     */
    public function forApp(string $app, ?CarbonImmutable $now = null, int $limit = 15): array
    {
        $now ??= CarbonImmutable::now();
        $spec = self::APPS[$app] ?? null;

        // Whole calendar months, not a rolling 183 days, so the month columns
        // line up with the months anyone would name out loud.
        $from = $now->startOfMonth()->subMonths(self::WINDOW_MONTHS - 1);
        $to = $now->endOfMonth();
        $months = $this->monthColumns($from);

        if ($spec === null || ! Schema::hasTable($spec['jobs']) || ! Schema::hasTable($spec['customers'])) {
            return $this->empty($months, $from, $to);
        }

        $bounds = RevenueTargets::windowBounds($from, $to);

        // 1. Everyone with collected work in the window. Not limited yet: the
        //    two groups are ranked separately, so the cut has to come after
        //    each customer's type is known.
        $ranked = DB::table($spec['jobs'])
            ->where('payment_status', 'paid')
            ->whereNotNull('customer_id')
            ->whereBetween($spec['date'], $bounds)
            ->selectRaw("customer_id, COALESCE(SUM({$spec['amount']}), 0) as paid, COUNT(*) as jobs")
            ->groupBy('customer_id')
            ->orderByDesc('paid')
            ->get();

        $collected = round((float) DB::table($spec['jobs'])
            ->where('payment_status', 'paid')
            ->whereBetween($spec['date'], $bounds)
            ->selectRaw("COALESCE(SUM({$spec['amount']}), 0) as total")
            ->value('total'), 3);

        if ($ranked->isEmpty()) {
            return $this->empty($months, $from, $to, $collected);
        }

        $customers = DB::table($spec['customers'])
            ->whereIn('id', $ranked->pluck('customer_id')->all())
            ->get(['id', 'name', 'type'])
            ->keyBy('id');

        // 2. Split, then take the top of each. A handful of corporate accounts
        //    would otherwise crowd out every individual and the individual half
        //    of the business would never be looked at.
        $keep = [];

        foreach (self::GROUPS as $group) {
            $of = $ranked->filter(function (object $row) use ($customers, $group): bool {
                $type = (string) ($customers[$row->customer_id]->type ?? 'individual');

                // Anything not explicitly a company is a person - a blank type
                // on an imported row must land somewhere, not vanish.
                return $group === 'company' ? $type === 'company' : $type !== 'company';
            })->take($limit)->values();

            $keep[$group] = $of;
        }

        /** @var list<int> $ids */
        $ids = [];

        foreach ($keep as $rows) {
            foreach ($rows as $row) {
                $ids[] = (int) $row->customer_id;
            }
        }

        // 3. Their WHOLE paid history in one query - not just the window. A
        //    rhythm measured only over six months would call a customer of ten
        //    years "new" and misjudge every gap at the window's edge. The month
        //    columns are filtered out of the same rows, so they cost nothing.
        $history = $ids === [] ? collect() : DB::table($spec['jobs'])
            ->where('payment_status', 'paid')
            ->whereIn('customer_id', $ids)
            ->whereNotNull($spec['date'])
            ->selectRaw("customer_id, {$spec['date']} as job_date, COALESCE({$spec['amount']}, 0) as amount")
            ->orderBy($spec['date'])
            ->get()
            ->groupBy('customer_id');

        $groups = [];

        foreach (self::GROUPS as $group) {
            $groups[$group] = $this->group($keep[$group], $customers, $history, $months, $collected, $now, $app, $spec);
        }

        return [
            'months' => $months,
            'groups' => $groups,
            'collected' => $collected,
            'windowMonths' => self::WINDOW_MONTHS,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, \stdClass>  $ranked
     * @param  \Illuminate\Support\Collection<int|string, \stdClass>  $customers
     * @param  \Illuminate\Support\Collection<int|string, \Illuminate\Support\Collection<int, \stdClass>>  $history
     * @param  list<array{key: string, label: string}>  $months
     * @param  array{jobs: string, customers: string, date: string, amount: string, href: string}  $spec
     * @return array{rows: list<array<string, mixed>>, total: float, share: int|null, quiet: int, atRisk: float}
     */
    private function group(
        \Illuminate\Support\Collection $ranked,
        \Illuminate\Support\Collection $customers,
        \Illuminate\Support\Collection $history,
        array $months,
        float $collected,
        CarbonImmutable $now,
        string $app,
        array $spec,
    ): array {
        $rows = [];
        $total = 0.0;
        $quiet = 0;
        $atRisk = 0.0;
        $keys = array_column($months, 'key');

        foreach ($ranked as $entry) {
            $id = (int) $entry->customer_id;
            $paid = round((float) $entry->paid, 3);
            $jobs = (int) $entry->jobs;
            $total += $paid;

            /** @var list<CarbonImmutable> $dates */
            $dates = [];
            /** @var list<array{date: CarbonImmutable, amount: float}> $entries */
            $entries = [];
            $byMonth = array_fill_keys($keys, 0.0);

            foreach ($history->get($id, collect()) as $job) {
                $date = CarbonImmutable::parse((string) $job->job_date);
                $amount = (float) $job->amount;
                $dates[] = $date;
                $entries[] = ['date' => $date, 'amount' => $amount];

                $key = $date->format('Y-m');

                if (array_key_exists($key, $byMonth)) {
                    $byMonth[$key] = round($byMonth[$key] + $amount, 3);
                }
            }

            $lastAt = $dates === [] ? null : end($dates);
            $daysSince = $lastAt === null ? null : (int) $lastAt->startOfDay()->diffInDays($now->startOfDay());
            $rhythm = $this->rhythm($dates);
            $status = $this->status($rhythm, $daysSince, count($dates));

            if ($status === 'slipping' || $status === 'lost') {
                $quiet++;
                $atRisk += $paid;
            }

            $rows[] = [
                'id' => $id,
                'name' => (string) ($customers[$id]->name ?? __('Unnamed customer')),
                'paid' => $paid,
                'jobs' => $jobs,
                'average' => $jobs > 0 ? round($paid / $jobs, 3) : 0.0,
                'months' => $byMonth,
                'lastAt' => $lastAt?->toDateString(),
                'daysSince' => $daysSince,
                'rhythm' => $rhythm,
                'status' => $status,
                'overdueBy' => $rhythm !== null && $daysSince !== null ? max(0, $daysSince - $rhythm) : null,
                'trend' => $this->trend($entries, $now),
                'href' => $app === 'limousine' ? $spec['href'].$id.'/summary' : $spec['href'].$id,
                'share' => $collected > 0.0 ? (int) round($paid / $collected * 100) : 0,
            ];
        }

        return [
            'rows' => $rows,
            'total' => round($total, 3),
            'share' => $collected > 0.0 ? (int) round($total / $collected * 100) : null,
            'quiet' => $quiet,
            'atRisk' => round($atRisk, 3),
        ];
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    private function monthColumns(CarbonImmutable $from): array
    {
        $months = [];

        for ($i = 0; $i < self::WINDOW_MONTHS; $i++) {
            $month = $from->addMonths($i);
            $months[] = ['key' => $month->format('Y-m'), 'label' => $month->isoFormat('MMM')];
        }

        return $months;
    }

    /**
     * How often this customer normally comes back, in days.
     *
     * The MEDIAN gap between consecutive jobs, not the mean: a single long
     * break in an otherwise regular customer would drag an average far enough
     * to excuse almost any silence. Fewer than two jobs is no rhythm at all,
     * and saying so is more honest than inventing one from a single date.
     *
     * @param  list<CarbonImmutable>  $dates  ascending
     */
    private function rhythm(array $dates): ?int
    {
        if (count($dates) < 2) {
            return null;
        }

        $gaps = [];

        for ($i = 1, $n = count($dates); $i < $n; $i++) {
            $gap = (int) $dates[$i - 1]->startOfDay()->diffInDays($dates[$i]->startOfDay());

            // Two jobs on the same day are one visit, not a one-day rhythm.
            if ($gap > 0) {
                $gaps[] = $gap;
            }
        }

        if ($gaps === []) {
            return null;
        }

        sort($gaps);
        $mid = intdiv(count($gaps), 2);

        $median = count($gaps) % 2 === 1
            ? $gaps[$mid]
            : ($gaps[$mid - 1] + $gaps[$mid]) / 2;

        return max(1, (int) round($median));
    }

    /**
     * Judged against this customer's own gap, never a single company-wide rule.
     */
    private function status(?int $rhythm, ?int $daysSince, int $jobs): string
    {
        if ($daysSince === null) {
            return 'new';
        }

        if ($rhythm === null || $jobs < 2) {
            // No rhythm to measure against, so fall back to plain elapsed time.
            return $daysSince > self::NO_RHYTHM_LOST_DAYS ? 'lost' : 'new';
        }

        $due = max($rhythm, self::GRACE_DAYS);

        if ($daysSince <= $due * self::SLIPPING_AT) {
            return 'active';
        }

        return $daysSince <= $due * self::LOST_AT ? 'slipping' : 'lost';
    }

    /**
     * Spending over the last quarter against the quarter before it.
     *
     * @param  list<array{date: CarbonImmutable, amount: float}>  $entries
     */
    private function trend(array $entries, CarbonImmutable $now): string
    {
        $recentFrom = $now->subDays(self::TREND_DAYS);
        $priorFrom = $now->subDays(self::TREND_DAYS * 2);

        $recent = 0.0;
        $prior = 0.0;

        foreach ($entries as $entry) {
            if ($entry['date'] > $recentFrom) {
                $recent += $entry['amount'];
            } elseif ($entry['date'] > $priorFrom) {
                $prior += $entry['amount'];
            }
        }

        if ($recent <= 0.0 && $prior <= 0.0) {
            return 'flat';
        }

        if ($prior <= 0.0) {
            return 'up';
        }

        if ($recent >= $prior * 1.15) {
            return 'up';
        }

        return $recent <= $prior * 0.85 ? 'down' : 'steady';
    }

    /**
     * @param  list<array{key: string, label: string}>  $months
     * @return array{months: list<array{key: string, label: string}>, groups: array<string, array{rows: list<array<string, mixed>>, total: float, share: int|null, quiet: int, atRisk: float}>, collected: float, windowMonths: int, from: string, to: string}
     */
    private function empty(array $months, CarbonImmutable $from, CarbonImmutable $to, float $collected = 0.0): array
    {
        $groups = [];

        foreach (self::GROUPS as $group) {
            $groups[$group] = ['rows' => [], 'total' => 0.0, 'share' => null, 'quiet' => 0, 'atRisk' => 0.0];
        }

        return [
            'months' => $months,
            'groups' => $groups,
            'collected' => $collected,
            'windowMonths' => self::WINDOW_MONTHS,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ];
    }
}
