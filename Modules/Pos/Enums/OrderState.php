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
        // `Done` is the persisted state for a completed sale — the
        // intermediate `Paid` state only exists for the instant between
        // markPaid() and finalizeSale() inside one DB transaction, so
        // no order ever actually sits in it. Labelling Done as "Paid"
        // matches what cashiers see in their head (the sale was paid)
        // and what shows up everywhere in the UI.
        return match ($this) {
            self::Draft => 'Draft',
            self::Paid => 'Paid',
            self::Done => 'Paid',
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
