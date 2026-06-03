<?php

declare(strict_types=1);

namespace Modules\Project\Enums;

/**
 * Odoo's per-task kanban "traffic light" — orthogonal to the stage the
 * task sits in. A task in any stage can be flagged blocked or ready/done.
 */
enum KanbanState: string
{
    case Normal = 'normal';
    case Blocked = 'blocked';
    case Done = 'done';

    public function label(): string
    {
        return match ($this) {
            self::Normal => __('In Progress'),
            self::Blocked => __('Blocked'),
            self::Done => __('Ready'),
        };
    }

    /** Tailwind tone token used for the card's accent border / dot. */
    public function color(): string
    {
        return match ($this) {
            self::Normal => 'chrome',
            self::Blocked => 'red',
            self::Done => 'emerald',
        };
    }
}
