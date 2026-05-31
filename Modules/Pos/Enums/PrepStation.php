<?php

declare(strict_types=1);

namespace Modules\Pos\Enums;

/**
 * Which Kitchen Display Screen a category routes to.
 *
 * `null` (NOT a case here — represented as `null` on the column itself)
 * means the category never enters the KDS at all. Adding a new station
 * = a new enum case + a new URL `/app/pos/kitchen/<value>` (the screen
 * is the same component, parameterised).
 */
enum PrepStation: string
{
    case Kitchen = 'kitchen';
    case Shisha = 'shisha';

    public function label(): string
    {
        return match ($this) {
            self::Kitchen => __('Kitchen'),
            self::Shisha => __('Shisha'),
        };
    }
}
