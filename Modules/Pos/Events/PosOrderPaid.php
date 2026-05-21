<?php

declare(strict_types=1);

namespace Modules\Pos\Events;

use Modules\Pos\Models\PosOrder;

/**
 * Fired exactly once per order, immediately after {@see PosOrder::finalizeSale()}
 * commits successfully. The Done state, payments and component consumption
 * are all already durable when this fires — listeners can read the order
 * freely without coordinating with the parent transaction.
 *
 * Carries the order itself (not just an id) so listeners avoid an extra
 * round-trip; the model is in its post-commit state.
 */
final class PosOrderPaid
{
    public function __construct(public readonly PosOrder $order)
    {
    }
}
