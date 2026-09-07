<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Calendar\EventWindow;
use App\Erp\Calendar\Hijri;
use App\Erp\Calendar\KnownEvents;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * The Hijri engine under the ad calendar. The tabular calendar is arithmetic
 * rather than observed, so it may sit a day off the moon-sighting date — the
 * anchors below allow that, the round-trip does not.
 */
final class HijriCalendarTest extends TestCase
{
    /**
     * @return list<array{0: int, 1: int, 2: int, 3: string}>
     */
    public static function observedDates(): array
    {
        return [
            [1446, 9, 1, '2025-03-01'],   // Ramadan 1446
            [1446, 10, 1, '2025-03-30'],  // Eid al-Fitr 1446
            [1446, 12, 10, '2025-06-06'], // Eid al-Adha 1446
            [1447, 1, 1, '2025-06-26'],   // Islamic New Year 1447
            [1447, 9, 1, '2026-02-18'],   // Ramadan 1447
            [1447, 10, 1, '2026-03-20'],  // Eid al-Fitr 1447
        ];
    }

    /**
     * @dataProvider observedDates
     */
    public function test_it_lands_within_a_day_of_the_observed_calendar(int $y, int $m, int $d, string $observed): void
    {
        $computed = Hijri::toGregorian($y, $m, $d);
        $gap = abs((int) $computed->diffInDays(CarbonImmutable::parse($observed), false));

        $this->assertLessThanOrEqual(1, $gap, "{$y}/{$m}/{$d} computed {$computed->toDateString()} vs observed {$observed}");
    }

    public function test_every_day_round_trips_exactly(): void
    {
        $day = CarbonImmutable::create(2019, 1, 1, 0, 0, 0);

        for ($i = 0; $i < 3000; $i++) {
            $h = Hijri::fromGregorian($day);
            $this->assertSame($day->toDateString(), Hijri::toGregorian($h['year'], $h['month'], $h['day'])->toDateString());
            $day = $day->addDay();
        }
    }

    public function test_an_islamic_window_moves_with_the_hijri_year(): void
    {
        $windows = KnownEvents::between(
            CarbonImmutable::create(2025, 1, 1, 0, 0, 0),
            CarbonImmutable::create(2026, 12, 31, 0, 0, 0),
            ['BH'],
        );

        $ramadans = array_values(array_filter($windows, static fn (EventWindow $w): bool => $w->key === 'ramadan'));
        $this->assertCount(2, $ramadans);

        // Eleven days earlier on the wall calendar, the same window by Hijri.
        $this->assertEqualsWithDelta(354, $ramadans[0]->start->diffInDays($ramadans[1]->start), 1.5);
        $this->assertSame($ramadans[0]->start->toDateString(), $ramadans[1]->lastYear()->start->toDateString());
        $this->assertTrue($ramadans[0]->hijri);
    }

    public function test_a_national_day_belongs_to_its_country(): void
    {
        $from = CarbonImmutable::create(2026, 1, 1, 0, 0, 0);
        $to = CarbonImmutable::create(2026, 12, 31, 0, 0, 0);

        $bahrainOnly = array_map(static fn (EventWindow $w): string => $w->key, KnownEvents::between($from, $to, ['BH']));
        $this->assertContains('bh_national_day', $bahrainOnly);
        $this->assertNotContains('sa_national_day', $bahrainOnly);

        $withSaudi = KnownEvents::between($from, $to, ['BH', 'SA']);
        $saudi = array_values(array_filter($withSaudi, static fn (EventWindow $w): bool => $w->key === 'sa_national_day'));
        $this->assertCount(1, $saudi);
        $this->assertSame('2026-09-23', $saudi[0]->start->toDateString());
        $this->assertSame('SA', $saudi[0]->country);
        $this->assertSame('2025-09-23', $saudi[0]->lastYear()->start->toDateString());
    }

    public function test_qatar_sports_day_is_the_second_tuesday_of_february(): void
    {
        foreach ([2026 => '2026-02-10', 2027 => '2027-02-09'] as $year => $expected) {
            $windows = KnownEvents::between(
                CarbonImmutable::create($year, 2, 1, 0, 0, 0),
                CarbonImmutable::create($year, 2, 28, 0, 0, 0),
                ['QA'],
            );
            $sports = array_values(array_filter($windows, static fn (EventWindow $w): bool => $w->key === 'qa_sports_day'));
            $this->assertCount(1, $sports);
            $this->assertSame($expected, $sports[0]->start->toDateString());
        }
    }

    public function test_no_markets_means_no_windows(): void
    {
        $this->assertSame([], KnownEvents::between(
            CarbonImmutable::create(2026, 1, 1, 0, 0, 0),
            CarbonImmutable::create(2026, 12, 31, 0, 0, 0),
            [],
        ));
    }
}
