<?php

declare(strict_types=1);

namespace Modules\Pos\Enums;

/**
 * How an order reached the shop: a walk-in sale at the counter, or a remote
 * order taken over phone / WhatsApp / Instagram for delivery or pickup. Same
 * register, same stock — the channel only changes what's captured (delivery
 * details) and where the order is reported (the Remote sales dashboard).
 */
enum SalesChannel: string
{
    case Shop = 'shop';
    case Remote = 'remote';

    public function label(): string
    {
        return match ($this) {
            self::Shop => 'Shop',
            self::Remote => 'Remote',
        };
    }
}
