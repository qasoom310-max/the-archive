<?php

declare(strict_types=1);

namespace Modules\Pos\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One static bill-of-materials line: one unit of the parent product consumes
 * `quantity_consumed` units of a component. The component is EITHER a product
 * (`component_product_id`) OR a condiment (`component_condiment_id`) OR an
 * ingredient / raw material (`component_ingredient_id`).
 *
 * @property int $id
 * @property int $parent_product_id
 * @property int|null $component_product_id
 * @property int|null $component_condiment_id
 * @property int|null $component_ingredient_id
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
        'component_ingredient_id',
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

    /**
     * @return BelongsTo<PosIngredient, $this>
     */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(PosIngredient::class, 'component_ingredient_id');
    }

    public function isCondiment(): bool
    {
        return $this->component_condiment_id !== null;
    }

    public function isIngredient(): bool
    {
        return $this->component_ingredient_id !== null;
    }

    /**
     * Resolve the component record this line points at (product, condiment or
     * ingredient), or null when none / the referenced record is gone. Kept as
     * an inline union (not a `?Model` return) so the concrete `name` /
     * `stock_on_hand` accessors below stay statically typed.
     */
    private function resolveComponent(): PosProduct|PosCondiment|PosIngredient|null
    {
        if ($this->isIngredient()) {
            return $this->ingredient;
        }

        if ($this->isCondiment()) {
            return $this->condiment;
        }

        return $this->component;
    }

    /**
     * The component's display name (product, condiment or ingredient), or null
     * if the referenced record is gone.
     */
    public function componentName(): ?string
    {
        $component = $this->resolveComponent();

        return $component?->name;
    }

    /**
     * The component's current on-hand stock, or null when the component can't
     * be resolved.
     */
    public function componentStock(): ?float
    {
        $component = $this->resolveComponent();

        return $component !== null ? (float) $component->stock_on_hand : null;
    }
}
