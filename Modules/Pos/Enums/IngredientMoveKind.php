<?php

declare(strict_types=1);

namespace Modules\Pos\Enums;

/**
 * What moved an ingredient's stock. Split into "in" and "out" kinds so the
 * ingredient screens can report purchased vs used without re-deriving intent
 * from the sign of every row.
 */
enum IngredientMoveKind: string
{
    /** Stock in — a confirmed purchase of the raw material. */
    case Purchase = 'purchase';

    /** Stock out — consumed by a production run (liquid or packaging). */
    case Production = 'production';

    /** Stock out — consumed by selling a product that lists it as a component. */
    case Sale = 'sale';

    /** Stock out — written off as damaged. */
    case Damage = 'damage';

    /**
     * A manual correction (the Stock Report's Adjust). Signed either way and
     * deliberately counted as NEITHER purchased nor used: it is a re-count, not
     * a real purchase or a real consumption, and folding it into either would
     * quietly falsify both figures.
     */
    case Adjustment = 'adjustment';

    public function label(): string
    {
        return match ($this) {
            self::Purchase => 'Purchase',
            self::Production => 'Production',
            self::Sale => 'Sale',
            self::Damage => 'Damage',
            self::Adjustment => 'Adjustment',
        };
    }

    /** Counts toward "how much have we bought". */
    public function isPurchase(): bool
    {
        return $this === self::Purchase;
    }

    /** Counts toward "how much have we actually used". */
    public function isConsumption(): bool
    {
        return in_array($this, [self::Production, self::Sale, self::Damage], true);
    }
}
