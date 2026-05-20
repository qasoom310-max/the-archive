<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * On-hand quantity of a product (optionally a lot) at one location.
 *
 * @property int $id
 * @property int $stock_location_id
 * @property int $product_id
 * @property string|null $lot_name
 * @property float $quantity
 * @property float $reserved_quantity
 */
final class StockQuant extends Model
{
    protected $table = 'stock_quants';

    /** @var list<string> */
    protected $fillable = [
        'stock_location_id', 'product_id', 'lot_name',
        'quantity', 'reserved_quantity',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'reserved_quantity' => 'float',
        ];
    }

    /**
     * @return BelongsTo<StockLocation, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'stock_location_id');
    }

    public function availableQuantity(): float
    {
        return round($this->quantity - $this->reserved_quantity, 3);
    }
}
