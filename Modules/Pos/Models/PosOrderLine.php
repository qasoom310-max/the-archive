<?php

declare(strict_types=1);

namespace Modules\Pos\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $pos_order_id
 * @property int|null $pos_product_id
 * @property string $name
 * @property float $qty
 * @property float $unit_price
 * @property float $discount
 * @property float $tax_rate
 * @property float $subtotal
 * @property float $tax_amount
 * @property float $total
 */
final class PosOrderLine extends Model
{
    protected $table = 'pos_order_lines';

    /** @var list<string> */
    protected $fillable = [
        'pos_order_id', 'pos_product_id', 'name', 'qty', 'unit_price',
        'discount', 'tax_rate', 'subtotal', 'tax_amount', 'total',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'qty' => 'float',
            'unit_price' => 'float',
            'discount' => 'float',
            'tax_rate' => 'float',
            'subtotal' => 'float',
            'tax_amount' => 'float',
            'total' => 'float',
        ];
    }

    /**
     * @return BelongsTo<PosOrder, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(PosOrder::class, 'pos_order_id');
    }

    /**
     * @return BelongsTo<PosProduct, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(PosProduct::class, 'pos_product_id');
    }

    /**
     * Recompute money fields from qty / price / discount / tax.
     */
    public function recompute(): void
    {
        $gross = $this->qty * $this->unit_price;
        $net = $gross * (1 - $this->discount / 100);

        $this->subtotal = round($net, 2);
        $this->tax_amount = round($net * $this->tax_rate / 100, 2);
        $this->total = round($this->subtotal + $this->tax_amount, 2);
    }
}
