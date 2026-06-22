<?php

declare(strict_types=1);

namespace Modules\Pos\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One static bill-of-materials line: one unit of the parent product consumes
 * `quantity_consumed` units of a component. The component is EITHER a product
 * (`component_product_id`) OR a condiment (`component_condiment_id`).
 *
 * @property int $id
 * @property int $parent_product_id
 * @property int|null $component_product_id
 * @property int|null $component_condiment_id
 * @property float $quantity_consumed
 */
final class PosProductRecipe extends Model
{
    protected $table = 'product_recipes';

    /** @var list<string> */
    protected $fillable = [
        'parent_product_id',
        'component_product_id',
        'component_condiment_id',
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

    /**
     * @return BelongsTo<PosCondiment, $this>
     */
    public function condiment(): BelongsTo
    {
        return $this->belongsTo(PosCondiment::class, 'component_condiment_id');
    }

    public function isCondiment(): bool
    {
        return $this->component_condiment_id !== null;
    }

    /**
     * The component's display name (product or condiment), or null if the
     * referenced record is gone.
     */
    public function componentName(): ?string
    {
        $component = $this->isCondiment() ? $this->condiment : $this->component;

        return $component?->name;
    }

    /**
     * The component's current on-hand stock (product or condiment), or null
     * when the component can't be resolved.
     */
    public function componentStock(): ?float
    {
        $component = $this->isCondiment() ? $this->condiment : $this->component;

        return $component !== null ? (float) $component->stock_on_hand : null;
    }
}
