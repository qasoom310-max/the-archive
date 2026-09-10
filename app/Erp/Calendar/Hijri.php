<?php

declare(strict_types=1);

namespace App\Erp\Calendar;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Gregorian ⇄ Hijri conversion — the tabular (civil, "Kuwaiti") calendar.
 *
 * Pure integer arithmetic on Julian Day Numbers, so it works without the
 * `intl` extension (not guaranteed on the production host). The tabular
 * calendar is arithmetic rather than observed, so it can sit a day off the
 * moon-sighting announcement — fine for planning ads, which run for weeks.
 *
 * Why this exists at all: Ramadan, both Eids and Ashura move ~11 days earlier
 * every Gregorian year. "Same week last year" is wrong for exactly the weeks
 * that matter most, so the calendar lines last year up by Hijri date.
 */
final class Hijri
{
    /** Julian Day Number of 1 Muharram 1 AH (16 July 622 Julian). */
    private const EPOCH = 1948440;

    public const MONTHS = [
        1 => 'Muharram', 2 => 'Safar', 3 => 'Rabi\' I', 4 => 'Rabi\' II',
        5 => 'Jumada I', 6 => 'Jumada II', 7 => 'Rajab', 8 => 'Sha\'ban',
        9 => 'Ramadan', 10 => 'Shawwal', 11 => 'Dhu al-Qi\'dah', 12 => 'Dhu al-Hijjah',
    ];

    /**
     * @return array{year: int, month: int, day: int}
     */
    public static function fromGregorian(DateTimeInterface $date): array
    {
        $jd = self::gregorianToJd((int) $date->format('Y'), (int) $date->format('n'), (int) $date->format('j'));

        $l = $jd - self::EPOCH + 10632;
        $n = intdiv($l - 1, 10631);
        $l = $l - 10631 * $n + 354;
        $j = intdiv(10985 - $l, 5316) * intdiv(50 * $l, 17719)
            + intdiv($l, 5670) * intdiv(43 * $l, 15238);
        $l = $l - intdiv(30 - $j, 15) * intdiv(17719 * $j, 50)
            - intdiv($j, 16) * intdiv(15238 * $j, 43) + 29;

        $month = intdiv(24 * $l, 709);
        $day = $l - intdiv(709 * $month, 24);
        $year = 30 * $n + $j - 30;

        return ['year' => $year, 'month' => $month, 'day' => $day];
    }

    public static function toGregorian(int $year, int $month, int $day): CarbonImmutable
    {
        $jd = intdiv(11 * $year + 3, 30) + 354 * $year + 30 * $month
            - intdiv($month - 1, 2) + $day + self::EPOCH - 385;

        [$gy, $gm, $gd] = self::jdToGregorian($jd);

        return CarbonImmutable::create($gy, $gm, $gd, 0, 0, 0);
    }

    /** Days in a tabular Hijri month (odd months 30, even 29, Dhu al-Hijjah 30 in leap years). */
    public static function daysInMonth(int $year, int $month): int
    {
        if ($month === 12) {
            return self::isLeapYear($year) ? 30 : 29;
        }

        return $month % 2 === 1 ? 30 : 29;
    }

    public static function isLeapYear(int $year): bool
    {
        return (11 * $year + 14) % 30 < 11;
    }

    public static function monthName(int $month): string
    {
        return self::MONTHS[$month] ?? '';
    }

    private static function gregorianToJd(int $y, int $m, int $d): int
    {
        $a = intdiv(14 - $m, 12);
        $y2 = $y + 4800 - $a;
        $m2 = $m + 12 * $a - 3;

        return $d + intdiv(153 * $m2 + 2, 5) + 365 * $y2 + intdiv($y2, 4)
            - intdiv($y2, 100) + intdiv($y2, 400) - 32045;
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private static function jdToGregorian(int $jd): array
    {
        $a = $jd + 32044;
        $b = intdiv(4 * $a + 3, 146097);
        $c = $a - intdiv(146097 * $b, 4);
        $d = intdiv(4 * $c + 3, 1461);
        $e = $c - intdiv(1461 * $d, 4);
        $m = intdiv(5 * $e + 2, 153);

        $day = $e - intdiv(153 * $m + 2, 5) + 1;
        $month = $m + 3 - 12 * intdiv($m, 10);
        $year = 100 * $b + $d - 4800 + intdiv($m, 10);

        return [$year, $month, $day];
    }
}
