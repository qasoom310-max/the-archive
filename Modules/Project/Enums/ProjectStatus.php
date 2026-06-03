<?php

declare(strict_types=1);

namespace Modules\Project\Enums;

/**
 * Lifecycle of a project. `archived` hides it from the active board list
 * without deleting its tasks / timesheet history.
 */
enum ProjectStatus: string
{
    case Active = 'active';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Active => __('Active'),
            self::Archived => __('Archived'),
        };
    }
}
