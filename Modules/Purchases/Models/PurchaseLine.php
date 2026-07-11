<?php

declare(strict_types=1);

namespace Modules\Purchases\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a vendor bill: a quantity of a catalogue product bought at a
 * unit cost. `pos_product_id` is a logical ref to `pos_products` (no FK —
 * keeps Purchases decoupled from the POS catalogue the same way Inventory is).
 *
 * @property int $id
 * @property int $purchase_id
 * @property int|null $pos_product_id
 * @property int|null $pos_condiment_id
 * @property int|null $pos_ingredient_id
 * @property string $description
 * @property float $quantity
 * @property float $unit_cost
 * @property float|null $landed_unit_cost  Unit cost + this line's delivery share (set at confirm)
 * @property float $subtotal
 */
final class PurchaseLine extends Model
{
    protected $table = 'purchase_lines';

    /** @var list<string> */
    protected $fillable = [
        'purchase_id', 'pos_product_id', 'pos_condiment_id', 'pos_ingredient_id', 'description', 'quantity', 'unit_cost', 'landed_unit_cost', 'subtotal',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'unit_cost' => 'float',
            'landed_unit_cost' => 'float',
            'subtotal' => 'float',
        ];
    }

    /** The cost used to value stock: landed cost if computed, else raw unit. */
    public function effectiveUnitCost(): float
    {
        return (float) ($this->landed_unit_cost ?? $this->unit_cost);
    }

    protected static function booted(): void
    {
        // Keep the line total derived from its inputs so it can never drift.
        static::saving(function (PurchaseLine $line): void {
            $line->subtotal = round((float) $line->quantity * (float) $line->unit_cost, 2);
        });
    }

    /**
     * @return BelongsTo<Purchase, $this>
     */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class, 'purchase_id');
    }
}
