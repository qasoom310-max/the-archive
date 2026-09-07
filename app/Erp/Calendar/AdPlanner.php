<?php

declare(strict_types=1);

namespace App\Erp\Calendar;

use App\Erp\Settings\Setting;
use App\Models\CalendarEvent;
use Carbon\CarbonImmutable;

/**
 * Turns last year's sales into this year's ad timing.
 *
 * Ads work when they run BEFORE demand, not during it — a car for Eid is
 * booked a fortnight out, a wedding limousine months out. So for every
 * selling window ahead this answers three things: how big was it last year
 * (aligned by Hijri date where that matters), how far ahead does this
 * business's customer decide (the per-app lead time), and therefore by which
 * date the ads must be live.
 *
 * The rules — lead days per app, which countries' holidays count — are
 * per database, read through the settings store so each business keeps its
 * own.
 */
final class AdPlanner
{
    /** @var array<string, int> */
    public const DEFAULT_LEAD_DAYS = ['rental' => 14, 'limousine' => 7, 'pos' => 3];

    /** @var list<string> */
    public const DEFAULT_MARKETS = ['BH', 'SA'];

    /** How far ahead the plan looks. */
    public const HORIZON_DAYS = 120;

    /** A week this far above the median is a peak worth naming; this far below, a trough. */
    public const PEAK_RATIO = 1.5;

    public const TROUGH_RATIO = 0.5;

    /** Cut-offs, as a share of the busiest days, for the heatmap's five shades. */
    private const SHADE_QUANTILES = [0.2, 0.4, 0.6, 0.8, 0.95];

    public function __construct(private readonly SalesHistory $history) {}

    /**
     * @return list<string>
     */
    public function sources(): array
    {
        return $this->history->sources();
    }

    /**
     * @return array<string, int>
     */
    public function leadDays(): array
    {
        $out = [];
        foreach (self::DEFAULT_LEAD_DAYS as $source => $default) {
            $value = Setting::get('adcal.lead_days.' . $source);
            $out[$source] = is_numeric($value) ? max(0, (int) $value) : $default;
        }

        return $out;
    }

    /**
     * @param  array<string, int>  $days
     */
    public function setLeadDays(array $days): void
    {
        foreach (self::DEFAULT_LEAD_DAYS as $source => $default) {
            Setting::set('adcal.lead_days.' . $source, max(0, (int) ($days[$source] ?? $default)));
        }
    }

    /**
     * Countries whose holidays this business tracks.
     *
     * @return list<string>
     */
    public function markets(): array
    {
        $raw = Setting::get('adcal.markets');
        $codes = is_string($raw) ? json_decode($raw, true) : null;

        if (! is_array($codes)) {
            return self::DEFAULT_MARKETS;
        }

        $codes = array_values(array_intersect(array_map('strval', $codes), array_keys(KnownEvents::COUNTRIES)));

        return $codes === [] ? self::DEFAULT_MARKETS : $codes;
    }

    /**
     * @param  list<string>  $codes
     */
    public function setMarkets(array $codes): void
    {
        $codes = array_values(array_intersect($codes, array_keys(KnownEvents::COUNTRIES)));
        Setting::set('adcal.markets', json_encode($codes === [] ? self::DEFAULT_MARKETS : $codes, JSON_THROW_ON_ERROR));
    }

    /**
     * Every selling window in range — region-wide Islamic, the chosen
     * countries' national days, and the owner's own — soonest first.
     *
     * @return list<EventWindow>
     */
    public function events(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $all = array_merge(
            KnownEvents::between($from, $to, $this->markets()),
            CalendarEvent::windowsBetween($from, $to),
        );

        usort($all, static fn (EventWindow $a, EventWindow $b): int => $a->start <=> $b->start);

        return $all;
    }

    /**
     * A normal week's revenue — the median of the last 52 weeks, ignoring
     * weeks before the first sale on record and weeks the business was
     * closed. The median so that Eid cannot inflate "normal".
     */
    public function baseline(CarbonImmutable $asOf): float
    {
        $weeks = $this->weeklyTotals($asOf);
        $values = array_values(array_map(static fn (array $w): float => $w['total'], $weeks));

        if ($values === []) {
            return 0.0;
        }

        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return round($n % 2 === 1 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2, 3);
    }

    /**
     * The last twelve months as a month-by-day grid, each day shaded by how
     * strong it was, marked with the windows that fell on it, and greyed
     * where there is no data rather than no demand.
     *
     * @return array<string, mixed>
     */
    public function heatmap(CarbonImmutable $asOf): array
    {
        $from = $asOf->subMonths(11)->startOfMonth();
        $to = $asOf->endOfMonth();

        $daily = $this->history->daily($from, $to);
        $firstRecord = $this->history->firstRecordDate();
        $events = $this->events($from, $to);
        $closed = array_values(array_filter($events, static fn (EventWindow $e): bool => $e->isClosed()));
        $shown = array_values(array_filter($events, static fn (EventWindow $e): bool => ! $e->isClosed()));

        $nonZero = [];
        foreach ($daily as $date => $day) {
            if ($day['total'] > 0 && ! $this->isNoData(CarbonImmutable::parse($date), $firstRecord, $closed)) {
                $nonZero[] = $day['total'];
            }
        }
        $thresholds = $this->quantiles($nonZero);

        $months = [];
        $bySource = ['rental' => 0.0, 'limousine' => 0.0, 'pos' => 0.0];
        $monthTotals = [];

        for ($cursor = $from; $cursor->lessThanOrEqualTo($to); $cursor = $cursor->addMonth()) {
            $key = $cursor->format('Y-m');
            $days = [];
            $monthTotal = 0.0;

            for ($d = $cursor->startOfMonth(); $d->lessThanOrEqualTo($cursor->endOfMonth()); $d = $d->addDay()) {
                $date = $d->toDateString();
                $row = $daily[$date] ?? ['rental' => 0.0, 'limousine' => 0.0, 'pos' => 0.0, 'total' => 0.0, 'count' => 0];
                $noData = $this->isNoData($d, $firstRecord, $closed);
                $monthTotal += $row['total'];

                foreach ($bySource as $source => $sum) {
                    $bySource[$source] = round($sum + $row[$source], 3);
                }

                $labels = [];
                $kinds = [];
                foreach ($shown as $event) {
                    if ($event->contains($d)) {
                        $labels[] = $event->label;
                        $kinds[$event->kind] = true;
                    }
                }

                $days[] = [
                    'day' => (int) $d->format('j'),
                    'date' => $date,
                    'total' => $row['total'],
                    'rental' => $row['rental'],
                    'limousine' => $row['limousine'],
                    'pos' => $row['pos'],
                    'count' => $row['count'],
                    'level' => $noData || $row['total'] <= 0 ? 0 : $this->level($row['total'], $thresholds),
                    'noData' => $noData,
                    'weekend' => in_array($d->dayOfWeek, [CarbonImmutable::FRIDAY, CarbonImmutable::SATURDAY], true),
                    'events' => $labels,
                    'eventKinds' => array_keys($kinds),
                ];
            }

            $monthTotals[$key] = round($monthTotal, 3);
            $months[] = ['key' => $key, 'label' => $cursor->isoFormat('MMM YYYY'), 'total' => round($monthTotal, 3), 'days' => $days];
        }

        $best = null;
        $worst = null;
        foreach ($months as $month) {
            if ($this->monthHasData($month)) {
                if ($best === null || $month['total'] > $best['total']) {
                    $best = ['label' => $month['label'], 'total' => $month['total']];
                }
                if ($worst === null || $month['total'] < $worst['total']) {
                    $worst = ['label' => $month['label'], 'total' => $month['total']];
                }
            }
        }

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'firstRecord' => $firstRecord?->toDateString(),
            'baseline' => $this->baseline($asOf),
            'total' => round(array_sum($monthTotals), 3),
            'bySource' => $bySource,
            'bestMonth' => $best,
            'worstMonth' => $worst,
            'months' => $months,
        ];
    }

    /**
     * The windows ahead, each with last year's figure for the same window
     * (Hijri-aligned where it matters) and the date the ads must be live by
     * for every app this business runs.
     *
     * @return list<array<string, mixed>>
     */
    public function plan(CarbonImmutable $today): array
    {
        $today = $today->startOfDay();
        $from = $today->subDays(3);
        $to = $today->addDays(self::HORIZON_DAYS);

        $baseline = $this->baseline($today);
        $firstRecord = $this->history->firstRecordDate();
        $leadDays = $this->leadDays();
        $sources = $this->sources();

        $out = [];

        foreach ($this->events($from, $to) as $event) {
            if ($event->isClosed() || $event->end->lessThan($from)) {
                continue;
            }

            $lastYear = $event->lastYear();
            $noData = $firstRecord === null || $lastYear->end->lessThan($firstRecord);
            $lastRevenue = $noData ? null : $this->history->total($lastYear->start, $lastYear->end);

            $uplift = null;
            if ($lastRevenue !== null && $baseline > 0) {
                $perDay = $lastRevenue / max(1, $lastYear->days());
                $uplift = round($perDay / ($baseline / 7) - 1, 3);
            }

            $launch = [];
            $overall = 'upcoming';
            if ($event->contains($today)) {
                $overall = 'live';
            } elseif ($event->end->lessThan($today)) {
                $overall = 'passed';
            }

            foreach ($sources as $source) {
                $by = $event->start->subDays($leadDays[$source] ?? 0);
                $status = match (true) {
                    $overall === 'live', $overall === 'passed' => $overall,
                    $by->lessThan($today) => 'overdue',
                    $by->lessThanOrEqualTo($today->addDays(3)) => 'now',
                    default => 'upcoming',
                };
                $launch[$source] = ['by' => $by->toDateString(), 'status' => $status];

                if ($overall === 'upcoming' && $status === 'overdue') {
                    $overall = 'overdue';
                } elseif ($overall === 'upcoming' && $status === 'now') {
                    $overall = 'now';
                }
            }

            $out[] = [
                'key' => $event->key,
                'label' => $event->label,
                'kind' => $event->kind,
                'country' => $event->country,
                'flag' => KnownEvents::COUNTRIES[$event->country][1] ?? '',
                'customId' => $event->customId,
                'start' => $event->start->toDateString(),
                'end' => $event->end->toDateString(),
                'days' => $event->days(),
                'daysUntil' => (int) $today->diffInDays($event->start, false),
                'lastYearStart' => $lastYear->start->toDateString(),
                'lastYearEnd' => $lastYear->end->toDateString(),
                'lastYearRevenue' => $lastRevenue,
                'uplift' => $uplift,
                'noData' => $noData,
                'launch' => $launch,
                'status' => $overall,
            ];
        }

        return $out;
    }

    /**
     * Weeks last year that were far above or below normal with no known
     * window on them — something happened there that the owner can name.
     *
     * @return list<array<string, mixed>>
     */
    public function unnamed(CarbonImmutable $asOf): array
    {
        $baseline = $this->baseline($asOf);
        if ($baseline <= 0) {
            return [];
        }

        $weeks = $this->weeklyTotals($asOf);
        $events = array_values(array_filter(
            $this->events($asOf->subWeeks(52), $asOf),
            static fn (EventWindow $e): bool => ! $e->isClosed(),
        ));

        $out = [];
        foreach ($weeks as $week) {
            $start = CarbonImmutable::parse($week['start']);
            $end = CarbonImmutable::parse($week['end']);

            $explained = false;
            foreach ($events as $event) {
                if ($event->overlaps($start, $end)) {
                    $explained = true;
                    break;
                }
            }
            if ($explained) {
                continue;
            }

            $ratio = round($week['total'] / $baseline, 2);
            if ($ratio >= self::PEAK_RATIO) {
                $out[] = ['start' => $week['start'], 'end' => $week['end'], 'total' => $week['total'], 'ratio' => $ratio, 'type' => 'peak'];
            } elseif ($ratio <= self::TROUGH_RATIO) {
                $out[] = ['start' => $week['start'], 'end' => $week['end'], 'total' => $week['total'], 'ratio' => $ratio, 'type' => 'trough'];
            }
        }

        return $out;
    }

    /**
     * Full Monday–Sunday weeks in the 52 ending at $asOf that carry real data.
     *
     * @return list<array{start: string, end: string, total: float}>
     */
    private function weeklyTotals(CarbonImmutable $asOf): array
    {
        $end = $asOf->startOfWeek()->subDay();
        $start = $end->subWeeks(52)->addDay();

        $daily = $this->history->daily($start, $end);
        $firstRecord = $this->history->firstRecordDate();
        $closed = array_values(array_filter(
            $this->events($start, $end),
            static fn (EventWindow $e): bool => $e->isClosed(),
        ));

        $weeks = [];
        for ($cursor = $start; $cursor->lessThan($end); $cursor = $cursor->addWeek()) {
            $weekEnd = $cursor->addDays(6);

            if ($firstRecord === null || $cursor->lessThan($firstRecord)) {
                continue;
            }

            $isClosed = false;
            foreach ($closed as $window) {
                if ($window->overlaps($cursor, $weekEnd)) {
                    $isClosed = true;
                    break;
                }
            }
            if ($isClosed) {
                continue;
            }

            $total = 0.0;
            for ($d = $cursor; $d->lessThanOrEqualTo($weekEnd); $d = $d->addDay()) {
                $total += $daily[$d->toDateString()]['total'] ?? 0.0;
            }

            $weeks[] = ['start' => $cursor->toDateString(), 'end' => $weekEnd->toDateString(), 'total' => round($total, 3)];
        }

        return $weeks;
    }

    /**
     * @param  list<EventWindow>  $closed
     */
    private function isNoData(CarbonImmutable $day, ?CarbonImmutable $firstRecord, array $closed): bool
    {
        if ($firstRecord === null || $day->lessThan($firstRecord)) {
            return true;
        }

        foreach ($closed as $window) {
            if ($window->contains($day)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $month
     */
    private function monthHasData(array $month): bool
    {
        /** @var list<array<string, mixed>> $days */
        $days = $month['days'];
        foreach ($days as $day) {
            if (! (bool) $day['noData']) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<float>  $values
     * @return list<float>
     */
    private function quantiles(array $values): array
    {
        if ($values === []) {
            return [];
        }

        sort($values);
        $n = count($values);

        return array_map(
            static fn (float $q): float => $values[min($n - 1, (int) floor($q * ($n - 1)))],
            self::SHADE_QUANTILES,
        );
    }

    /**
     * @param  list<float>  $thresholds
     */
    private function level(float $total, array $thresholds): int
    {
        if ($thresholds === []) {
            return 1;
        }

        $level = 1;
        foreach ($thresholds as $threshold) {
            if ($total > $threshold) {
                $level++;
            }
        }

        return min(6, $level);
    }
}
