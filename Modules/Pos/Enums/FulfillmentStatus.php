<?php

declare(strict_types=1);

namespace Modules\Pos\Enums;

/**
 * The delivery pipeline of a remote order, after it's paid: it's picked/packed,
 * handed to a driver, then delivered. Drives the Remote sales dashboard, which
 * is a to-do list of orders still to fulfill.
 */
enum FulfillmentStatus: string
{
    case New = 'new';
    case Packed = 'packed';
    case OutForDelivery = 'out_for_delivery';
    case Delivered = 'delivered';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Packed => 'Packed',
            self::OutForDelivery => 'Out for delivery',
            self::Delivered => 'Delivered',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::New => 'amber',
            self::Packed => 'sky',
            self::OutForDelivery => 'indigo',
            self::Delivered => 'emerald',
        };
    }

    /** The next step in the pipeline, or null when it's already delivered. */
    public function next(): ?self
    {
        return match ($this) {
            self::New => self::Packed,
            self::Packed => self::OutForDelivery,
            self::OutForDelivery => self::Delivered,
            self::Delivered => null,
        };
    }

    /** Label of the button that advances to {@see next()} ("Mark packed"…). */
    public function advanceLabel(): ?string
    {
        return match ($this) {
            self::New => 'Mark packed',
            self::Packed => 'Send out for delivery',
            self::OutForDelivery => 'Mark delivered',
            self::Delivered => null,
        };
    }
}
