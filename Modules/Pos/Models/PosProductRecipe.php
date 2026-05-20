<?php

declare(strict_types=1);

namespace Modules\Pos\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One static bill-of-materials line: one unit of the parent product
 * consumes `quantity_consumed` units of the component product.
 *
 * @property int $id
 * @property int $parent_product_id
 * @property int $component_product_id
 * @property float $quantity_consumed
 */
final class PosProductRecipe extends Model
{
    protected $table = 'product_recipes';

    /** @var list<string> */
    protected $fillable = [
        'parent_product_id',
        'component_product_id',
        'quantity_consumed',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['quantity_consumed' => 'float'];
    }

    /**
     * @return BelongsTo<PosProduct, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(PosProduct::class, 'parent_product_id');
    }

    /**
     * @return BelongsTo<PosProduct, $this>
     */
    public function component(): BelongsTo
    {
        return $this->belongsTo(PosProduct::class, 'component_product_id');
    }
}
