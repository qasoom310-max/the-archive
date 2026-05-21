<?php

declare(strict_types=1);

namespace App\Erp\Views;

use Illuminate\Support\Carbon;

/**
 * Resolves a named date-range preset into a concrete `[$start, $end]`
 * pair of {@see Carbon} instants. Centralised so list filters, KPI
 * widgets and any future "this period" report all agree on what
 * "this week" means (Mon→Sun, inclusive, local timezone).
 *
 * Add a new preset by extending the `match` in {@see range()} — the
 * name becomes available everywhere that consumes filter arch.
 */
final class DatePreset
{
    /**
     * @return list<array{name: string, label: string}>
     */
    public static function available(): array
    {
        return [
            ['name' => 'today',      'label' => 'Today'],
            ['name' => 'yesterday',  'label' => 'Yesterday'],
            ['name' => 'this_week',  'label' => 'This Week'],
            ['name' => 'this_month', 'label' => 'This Month'],
        ];
    }

    public static function isValid(string $name): bool
    {
        foreach (self::available() as $preset) {
            if ($preset['name'] === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve a preset name to `[$start, $end]` Carbon instants
     * (both inclusive). Returns null for unknown presets so the
     * caller can treat that as "no filter applied".
     *
     * @return array{0: Carbon, 1: Carbon}|null
     */
    public static function range(string $name): ?array
    {
        return match ($name) {
            'today'      => [Carbon::today(), Carbon::today()->endOfDay()],
            'yesterday'  => [Carbon::yesterday(), Carbon::yesterday()->endOfDay()],
            'this_week'  => [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()],
            'this_month' => [Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth()],
            default      => null,
        };
    }
}
