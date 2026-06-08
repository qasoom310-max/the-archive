<?php

declare(strict_types=1);

namespace Modules\Purchases\Enums;

/**
 * Lifecycle of a vendor bill. A bill is editable while Draft; confirming it
 * is the irreversible point that receives stock and posts to accounting.
 */
enum PurchaseState: string
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';

    /**
     * User-facing label. Run through `__()` at the call-site (never here) so
     * the enum stays locale-agnostic — same pattern as OrderState/SessionState.
     */
    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Confirmed => 'Confirmed',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * Tailwind tone token for the engine list badge.
     */
    public function color(): string
    {
        return match ($this) {
            self::Draft => 'amber',
            self::Confirmed => 'emerald',
            self::Cancelled => 'red',
        };
    }
}
