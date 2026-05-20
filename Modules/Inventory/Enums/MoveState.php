<?php

declare(strict_types=1);

namespace Modules\Inventory\Enums;

/**
 * Lifecycle of a stock move (mirrors Odoo's stock.move state machine,
 * trimmed for this scaffold).
 */
enum MoveState: string
{
    case Draft = 'draft';          // not yet confirmed
    case Confirmed = 'confirmed';  // confirmed, awaiting availability
    case Assigned = 'assigned';    // reserved / ready to process
    case Done = 'done';            // completed
    case Cancelled = 'cancelled';  // cancelled

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Confirmed => 'Waiting',
            self::Assigned => 'Ready',
            self::Done => 'Done',
            self::Cancelled => 'Cancelled',
        };
    }

    /** Still an active task on the dashboard (not finished/cancelled). */
    public function isOpen(): bool
    {
        return $this === self::Draft
            || $this === self::Confirmed
            || $this === self::Assigned;
    }
}
