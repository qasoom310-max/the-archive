<?php

declare(strict_types=1);

namespace Modules\Pos\Enums;

enum OrderState: string
{
    case Draft = 'draft';
    case Paid = 'paid';
    case Done = 'done';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Paid => 'Paid',
            self::Done => 'Posted',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'amber',
            self::Paid => 'sky',
            self::Done => 'emerald',
            self::Cancelled => 'red',
        };
    }
}
