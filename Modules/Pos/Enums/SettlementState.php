<?php

declare(strict_types=1);

namespace Modules\Pos\Enums;

/**
 * Where a payout from the delivery company has got to.
 *
 * `Requested` — we've asked for the money; it is still with them.
 * `Received`  — it landed in our account and was checked against what we asked
 *               for (any shortfall is recorded as the settlement's difference).
 */
enum SettlementState: string
{
    case Requested = 'requested';
    case Received = 'received';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Requested',
            self::Received => 'Received',
        };
    }

    /** Tailwind tint key used by the badge in the settlements list. */
    public function color(): string
    {
        return match ($this) {
            self::Requested => 'amber',
            self::Received => 'emerald',
        };
    }
}
