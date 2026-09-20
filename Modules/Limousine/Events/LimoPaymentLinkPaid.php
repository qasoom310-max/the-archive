<?php

declare(strict_types=1);

namespace Modules\Limousine\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A service-order payment link was paid and the booking credited. Fired once,
 * after the settling transaction commits (a redelivered callback fires
 * nothing). Listened to by the WhatsApp staff assistant, which tells whoever
 * raised the link.
 */
final class LimoPaymentLinkPaid
{
    use Dispatchable;

    public function __construct(public readonly int $paymentLinkId)
    {
    }
}
