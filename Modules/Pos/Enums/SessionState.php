<?php

declare(strict_types=1);

namespace Modules\Pos\Enums;

enum SessionState: string
{
    case Opened = 'opened';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Opened => 'In progress',
            self::Closed => 'Closed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Opened => 'emerald',
            self::Closed => 'chrome',
        };
    }
}
