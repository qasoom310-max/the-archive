<?php

declare(strict_types=1);

namespace App\Erp\Views;

use BackedEnum;
use UnitEnum;

/**
 * Shared value normalisation for the metadata-driven List/Kanban views.
 *
 * Eloquent enum-cast columns (e.g. an order's `state`) surface as enum
 * *objects*, which cannot be cast to string in Blade. These helpers keep
 * the engine generic: any column — enum or scalar — stays renderable and
 * groupable without the views knowing the concrete model.
 */
final class ValueFormat
{
    /**
     * Stable identity/grouping key. For a backed enum this is the backing
     * value (so it matches the arch stage `value`s); for a pure enum, the
     * case name. Null becomes the empty string.
     */
    public static function key(mixed $value): string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        return $value === null ? '' : (string) $value;
    }

    /**
     * Human-facing value for display in a cell/badge. An enum exposing a
     * `label()` method is rendered with it; otherwise the backing value
     * (backed enum) or case name (pure enum). Non-enums pass through
     * untouched so Carbon/number formatting downstream still applies.
     */
    public static function label(mixed $value): mixed
    {
        if (! $value instanceof UnitEnum) {
            return $value;
        }

        if (method_exists($value, 'label')) {
            return $value->label();
        }

        return $value instanceof BackedEnum ? $value->value : $value->name;
    }
}
