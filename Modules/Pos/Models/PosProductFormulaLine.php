<?php

declare(strict_types=1);

namespace Modules\Pos\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a product's standard production formula: `ml` of an ingredient per
 * batch. See {@see PosProduct::formulaLines()}.
 *
 * @property int $id
 * @property int $pos_product_id
 * @property int $pos_ingredient_id
 * @property string $kind  liquid (ml) | packaging (qty_per_unit)
 * @property float $ml
 * @property float|null $qty_per_unit
 * @property int $sequence
 * @property-read PosIngredient|null $ingredient
 */
final class PosProductFormulaLine extends Model
{
    protected $table = 'pos_product_formula_lines';

    public const KIND_LIQUID = 'liquid';

    public const KIND_PACKAGING = 'packaging';

    /** @var list<string> */
    protected $fillable = ['pos_product_id', 'pos_ingredient_id', 'kind', 'ml', 'qty_per_unit', 'sequence'];

    /** @var array<string, mixed> */
    protected $attributes = ['kind' => 'liquid'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pos_product_id' => 'integer',
            'pos_ingredient_id' => 'integer',
            'ml' => 'float',
            'qty_per_unit' => 'float',
            'sequence' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<PosIngredient, $this>
     */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(PosIngredient::class, 'pos_ingredient_id');
    }
}
