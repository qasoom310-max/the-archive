<?php

declare(strict_types=1);

namespace Modules\Pos\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One raw material consumed by a {@see PosProduction}: `ml_used` of an
 * ingredient, at its cost per ML at the time.
 *
 * @property int $id
 * @property int $pos_production_id
 * @property int $pos_ingredient_id
 * @property string $kind  liquid (ml_used total) | packaging (qty_per_unit per bottle)
 * @property float $ml_used
 * @property float|null $stock_qty  Stock units this line actually deducted (null on pre-2026-08 runs)
 * @property float|null $qty_per_unit
 * @property float $unit_cost
 * @property-read PosIngredient|null $ingredient
 */
final class PosProductionLine extends Model
{
    protected $table = 'pos_production_lines';

    public const KIND_LIQUID = 'liquid';

    public const KIND_PACKAGING = 'packaging';

    /** @var list<string> */
    protected $fillable = ['pos_production_id', 'pos_ingredient_id', 'kind', 'ml_used', 'stock_qty', 'qty_per_unit', 'unit_cost'];

    /** @var array<string, mixed> */
    protected $attributes = ['kind' => self::KIND_LIQUID];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pos_production_id' => 'integer',
            'pos_ingredient_id' => 'integer',
            'ml_used' => 'float',
            'stock_qty' => 'float',
            'qty_per_unit' => 'float',
            'unit_cost' => 'float',
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
