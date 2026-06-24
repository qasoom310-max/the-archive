<?php

declare(strict_types=1);

namespace App\Erp\Hr;

use Illuminate\Support\Carbon;

/**
 * Splits a worked overtime interval into Bahrain pay bands:
 *   - DAY   07:00–19:00  → ×1.2
 *   - NIGHT 19:00–07:00  → ×1.5
 *
 * Handles intervals that cross midnight and span multiple day windows.
 */
final class OvertimeCalculator
{
    public const DAY_START_HOUR = 7;   // 07:00

    public const DAY_END_HOUR = 19;    // 19:00

    public const DAY_MULTIPLIER = 1.2;

    public const NIGHT_MULTIPLIER = 1.5;

    /**
     * Hours of the interval falling in each band.
     *
     * @return array{day: float, night: float}
     */
    public function split(Carbon $start, Carbon $end): array
    {
        if ($end <= $start) {
            return ['day' => 0.0, 'night' => 0.0];
        }

        $dayMinutes = 0;
        $totalMinutes = 0;
        $cursor = $start->copy();

        // Walk the interval one calendar day at a time so each segment is
        // compared against that day's 07:00–19:00 window.
        while ($cursor < $end) {
            $nextMidnight = $cursor->copy()->addDay()->startOfDay();
            $segmentEnd = $end < $nextMidnight ? $end->copy() : $nextMidnight;

            $dayStart = $cursor->copy()->setTime(self::DAY_START_HOUR, 0);
            $dayEnd = $cursor->copy()->setTime(self::DAY_END_HOUR, 0);

            $overlapStart = $cursor > $dayStart ? $cursor : $dayStart;
            $overlapEnd = $segmentEnd < $dayEnd ? $segmentEnd : $dayEnd;

            if ($overlapEnd > $overlapStart) {
                $dayMinutes += $overlapStart->diffInMinutes($overlapEnd);
            }

            $totalMinutes += $cursor->diffInMinutes($segmentEnd);
            $cursor = $segmentEnd->copy();
        }

        $day = round($dayMinutes / 60, 3);
        $night = round(($totalMinutes - $dayMinutes) / 60, 3);

        return ['day' => $day, 'night' => $night];
    }
}
