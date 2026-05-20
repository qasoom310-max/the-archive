<?php

declare(strict_types=1);

namespace App\Erp\Chatter;

/**
 * Odoo 19 activity scheduling buckets. Open activities are grouped by how
 * their due date compares to "today"; completed activities fall into Done
 * (historical) rather than disappearing.
 */
enum ActivityBucket: string
{
    case Overdue = 'overdue';
    case Today = 'today';
    case Tomorrow = 'tomorrow';
    case Planned = 'planned';
    case Done = 'done';

    public function label(): string
    {
        return match ($this) {
            self::Overdue => 'Overdue',
            self::Today => 'Today',
            self::Tomorrow => 'Tomorrow',
            self::Planned => 'Planned',
            self::Done => 'Done',
        };
    }

    /** Tailwind colour token used by the Chatter UI. */
    public function color(): string
    {
        return match ($this) {
            self::Overdue => 'red',
            self::Today => 'amber',
            self::Tomorrow => 'sky',
            self::Planned => 'chrome',
            self::Done => 'emerald',
        };
    }

    public function isOpen(): bool
    {
        return $this !== self::Done;
    }
}
