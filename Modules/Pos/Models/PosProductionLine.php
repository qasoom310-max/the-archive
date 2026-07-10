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
 * @property float $ml_used
 * @property float $unit_cost
 * @property-read PosIngredient|null $ingredient
 */
final class PosProductionLine extends Model
{
    protected $table = 'pos_production_lines';

    /** @var list<string> */
    protected $fillable = ['pos_production_id', 'pos_ingredient_id', 'ml_used', 'unit_cost'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pos_production_id' => 'integer',
            'pos_ingredient_id' => 'integer',
            'ml_used' => 'float',
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
