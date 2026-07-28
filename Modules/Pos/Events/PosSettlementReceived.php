<?php

declare(strict_types=1);

namespace Modules\Pos\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Modules\Pos\Models\PosSettlement;

/**
 * A delivery-company payout (or bank transfer) landed in our account. The
 * Accounting module listens to move the money out of "in transit" and into the
 * bank — see {@see \Modules\Accounting\Listeners\RecordSettlementInJournal}.
 */
final class PosSettlementReceived
{
    use Dispatchable;

    public function __construct(public PosSettlement $settlement)
    {
    }
}
