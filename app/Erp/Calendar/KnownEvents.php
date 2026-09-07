<?php

declare(strict_types=1);

namespace App\Erp\Calendar;

use Carbon\CarbonImmutable;

/**
 * The selling seasons of the Gulf, computed for any window.
 *
 * Two layers. The Islamic dates (Ramadan, both Eids, Ashura, …) are shared by
 * every GCC country and come off the Hijri calendar — they drift 11 days a
 * year on the wall calendar. The national days are fixed and belong to ONE
 * country each; they matter because a long weekend in Saudi, Kuwait or Qatar
 * is a busy weekend in Bahrain. Which countries a business cares about is a
 * per-database setting ({@see AdPlanner::markets()}).
 *
 * Anything specific to one business — F1 weekend, a wedding season, a
 * closure — is a {@see \App\Models\CalendarEvent} the owner adds themselves.
 */
final class KnownEvents
{
    /**
     * Countries whose holidays can be tracked: code → [label, flag].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const COUNTRIES = [
        'BH' => ['Bahrain', '🇧🇭'],
        'SA' => ['Saudi Arabia', '🇸🇦'],
        'KW' => ['Kuwait', '🇰🇼'],
        'AE' => ['UAE', '🇦🇪'],
        'QA' => ['Qatar', '🇶🇦'],
        'OM' => ['Oman', '🇴🇲'],
    ];

    /**
     * Islamic windows as [key, label, hijriMonth, firstDay, lastDay] — lastDay
     * null = to the end of the month. The Eid spans cover the longest official
     * break in the region (Saudi and Qatar give four days plus the weekend).
     *
     * @var list<array{0: string, 1: string, 2: int, 3: int, 4: int|null}>
     */
    private const ISLAMIC = [
        ['islamic_new_year', 'Islamic New Year', 1, 1, 1],
        ['ashura', 'Ashura', 1, 9, 10],
        ['mawlid', 'Prophet\'s Birthday', 3, 12, 12],
        ['isra_miraj', 'Isra and Mi\'raj', 7, 27, 27],
        ['ramadan', 'Ramadan', 9, 1, null],
        ['eid_fitr', 'Eid al-Fitr', 10, 1, 4],
        ['eid_adha', 'Eid al-Adha', 12, 9, 13],
    ];

    /**
     * Fixed national windows per country as [key, label, month, firstDay, lastDay].
     *
     * @var array<string, list<array{0: string, 1: string, 2: int, 3: int, 4: int}>>
     */
    private const NATIONAL = [
        'BH' => [
            ['bh_new_year', 'New Year', 1, 1, 1],
            ['bh_labour_day', 'Labour Day', 5, 1, 1],
            ['bh_national_day', 'Bahrain National Day', 12, 16, 17],
        ],
        'SA' => [
            ['sa_founding_day', 'Saudi Founding Day', 2, 22, 22],
            ['sa_national_day', 'Saudi National Day', 9, 23, 23],
        ],
        'KW' => [
            ['kw_new_year', 'New Year', 1, 1, 1],
            ['kw_national_day', 'Kuwait National & Liberation Day', 2, 25, 26],
        ],
        'AE' => [
            ['ae_new_year', 'New Year', 1, 1, 1],
            ['ae_national_day', 'UAE National Day', 12, 1, 3],
        ],
        'QA' => [
            ['qa_national_day', 'Qatar National Day', 12, 18, 18],
        ],
        'OM' => [
            ['om_new_year', 'New Year', 1, 1, 1],
            ['om_accession_day', 'Oman Accession Day', 1, 11, 11],
            ['om_national_day', 'Oman National Day', 11, 18, 19],
        ],
    ];

    /**
     * Every window overlapping [$from, $to] for the given markets, soonest
     * first. Islamic windows are included whenever at least one market is
     * selected — they belong to the whole region.
     *
     * @param  list<string>  $markets  country codes from {@see COUNTRIES}
     * @return list<EventWindow>
     */
    public static function between(CarbonImmutable $from, CarbonImmutable $to, array $markets): array
    {
        $markets = array_values(array_intersect($markets, array_keys(self::COUNTRIES)));
        if ($markets === []) {
            return [];
        }

        $out = [];

        $firstHijri = Hijri::fromGregorian($from)['year'];
        $lastHijri = Hijri::fromGregorian($to)['year'];

        for ($year = $firstHijri - 1; $year <= $lastHijri + 1; $year++) {
            foreach (self::ISLAMIC as [$key, $label, $month, $firstDay, $lastDay]) {
                $window = new EventWindow(
                    $key,
                    $label,
                    Hijri::toGregorian($year, $month, $firstDay),
                    Hijri::toGregorian($year, $month, $lastDay ?? Hijri::daysInMonth($year, $month)),
                    EventWindow::KIND_ISLAMIC,
                    EventWindow::COUNTRY_GCC,
                    hijri: true,
                );

                if ($window->overlaps($from, $to)) {
                    $out[] = $window;
                }
            }
        }

        for ($year = (int) $from->format('Y'); $year <= (int) $to->format('Y'); $year++) {
            foreach ($markets as $country) {
                foreach (self::NATIONAL[$country] ?? [] as [$key, $label, $month, $firstDay, $lastDay]) {
                    $window = new EventWindow(
                        $key,
                        $label,
                        CarbonImmutable::create($year, $month, $firstDay, 0, 0, 0),
                        CarbonImmutable::create($year, $month, $lastDay, 0, 0, 0),
                        EventWindow::KIND_NATIONAL,
                        $country,
                    );

                    if ($window->overlaps($from, $to)) {
                        $out[] = $window;
                    }
                }
            }

            // Qatar National Sports Day is the second Tuesday of February.
            if (in_array('QA', $markets, true)) {
                $feb = CarbonImmutable::create($year, 2, 1, 0, 0, 0);
                $sports = ($feb->isTuesday() ? $feb : $feb->next('Tuesday'))->addWeek();
                $window = new EventWindow('qa_sports_day', 'Qatar National Sports Day', $sports, $sports, EventWindow::KIND_NATIONAL, 'QA');
                if ($window->overlaps($from, $to)) {
                    $out[] = $window;
                }
            }
        }

        usort($out, static fn (EventWindow $a, EventWindow $b): int => $a->start <=> $b->start);

        return $out;
    }
}
