<?php

declare(strict_types=1);

namespace Modules\Pos\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Pos\Enums\IngredientMoveKind;

/**
 * One movement of an ingredient's stock — the history behind
 * `pos_ingredients.stock_on_hand`.
 *
 * Append-only by convention: rows are written by {@see PosIngredient::applyStockDelta()}
 * and never edited afterwards. Correcting a mistake means recording another
 * move, not rewriting this one — the point of the table is that it stays a
 * faithful account of what happened.
 *
 * @property int $id
 * @property int $pos_ingredient_id
 * @property float $qty  Signed delta in stock units: + = in, - = out
 * @property IngredientMoveKind $kind
 * @property string|null $reference
 * @property float $balance_after  On-hand immediately after this move
 * @property int|null $user_id
 * @property Carbon|null $created_at
 */
final class PosIngredientMove extends Model
{
    protected $table = 'pos_ingredient_moves';

    /** Append-only: `created_at` is stamped on insert, and nothing updates. */
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['pos_ingredient_id', 'qty', 'kind', 'reference', 'balance_after', 'user_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'qty' => 'float',
            'balance_after' => 'float',
            'kind' => IngredientMoveKind::class,
            'user_id' => 'integer',
            'created_at' => 'datetime',
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
