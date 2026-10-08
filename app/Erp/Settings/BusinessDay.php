<?php

declare(strict_types=1);

namespace App\Erp\Settings;

use Illuminate\Support\Carbon;

/**
 * The trading day, which need not start at midnight.
 *
 * A café open until the small hours counts a 2 AM sale for the evening it
 * belongs to, not the next calendar day. `company.day_starts_at` is the hour
 * (0–23) a business day begins, per database; 0 keeps plain calendar days.
 * Times are in the company timezone, which is the app timezone once
 * {@see CompanyTimezone::apply()} has run.
 */
final class BusinessDay
{
    public static function startHour(): int
    {
        $raw = Setting::get('company.day_starts_at', 0);

        return is_numeric($raw) ? max(0, min(23, (int) $raw)) : 0;
    }

    /** The business day a moment falls in, as Y-m-d (defaults to now). */
    public static function of(?Carbon $at = null): string
    {
        return ($at ?? Carbon::now())->copy()->subHours(self::startHour())->toDateString();
    }

    /**
     * The window [from, to) of a business day.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function window(string $date): array
    {
        $from = Carbon::parse($date)->startOfDay()->addHours(self::startHour());

        return [$from, $from->copy()->addDay()];
    }
}
