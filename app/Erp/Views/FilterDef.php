<?php

declare(strict_types=1);

namespace App\Erp\Views;

/**
 * One filter preset on a List view, parsed from `arch.filters[]`. Each
 * preset names a date range (`today` / `yesterday` / `this_week` /
 * `this_month`) applied as a WHERE clause on `$field` — the engine
 * resolves the preset to a `Carbon` window through {@see DatePreset}.
 *
 * Kept deliberately narrow: presets are well-known strings, not raw
 * SQL fragments, so the surface stays metadata-only and the engine can
 * statically validate them. Add a new preset by extending
 * `DatePreset::range()`, then reference its name from an arch.
 */
final readonly class FilterDef
{
    public function __construct(
        /** Stable URL-safe identifier (e.g. `today`, `this_week`). */
        public string $name,
        /** Human label shown on the chip (e.g. "Today's Sales"). */
        public string $label,
        /** Model column to filter on (typically a `datetime` cast). */
        public string $field,
        /** Preset name resolved by {@see DatePreset}. */
        public string $preset,
    ) {}
}
