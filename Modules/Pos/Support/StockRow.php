<?php

declare(strict_types=1);

namespace Modules\Pos\Support;

/**
 * One row of the Stock Report — a stock-tracked item (a product, a condiment /
 * add-on, or a raw-material ingredient) already bucketed into its stock-health
 * status. Lets the report treat all catalogues uniformly across the on-screen
 * view, the CSV export and the printable slip.
 *
 * Condiments carry no cost / reorder-point / barcode, so those are null/0 for
 * them (value is therefore always 0 — condiment cost isn't tracked).
 * Ingredients carry a tracked cost (so they contribute to valuation) and a
 * unit, but no reorder point / barcode.
 */
final class StockRow
{
    public function __construct(
        /** 'product' | 'condiment' | 'ingredient'. */
        public readonly string $type,
        public readonly int $id,
        public readonly string $name,
        public readonly ?string $category,
        public readonly float $stock,
        /** Unit suffix ('' when none / the default 'qty'). */
        public readonly string $unit,
        public readonly float $cost,
        public readonly float $value,
        public readonly ?float $reorderPoint,
        /** 'in' | 'low' | 'out'. */
        public readonly string $status,
        public readonly bool $active,
        public readonly ?string $barcode,
        /** Back-store stock (products with production); null when N/A. */
        public readonly ?float $storeStock = null,
    ) {
    }

    public function isProduct(): bool
    {
        return $this->type === 'product';
    }

    public function isCondiment(): bool
    {
        return $this->type === 'condiment';
    }

    public function isIngredient(): bool
    {
        return $this->type === 'ingredient';
    }

    /** Edit-page URL for the underlying record. */
    public function url(): string
    {
        return match ($this->type) {
            'condiment' => '/app/pos/condiment/' . $this->id,
            'ingredient' => '/app/pos/ingredient/' . $this->id,
            default => '/app/pos/product/' . $this->id,
        };
    }
}
