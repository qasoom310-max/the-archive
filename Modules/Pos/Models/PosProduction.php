<?php

declare(strict_types=1);

namespace Modules\Pos\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Pos\Enums\IngredientMoveKind;

/**
 * A production run (perfumes POS): raw materials (ingredients, in ML) mixed into
 * finished bottles. The mix ÷ bottle size gives the expected bottle count; the
 * actual produced count lands in the product's STORE stock, and the materials
 * are deducted from ingredient stock. `expected − produced` is the waste alarm.
 *
 * @property int $id
 * @property string|null $reference
 * @property int $pos_product_id
 * @property int|null $pos_session_id
 * @property float $bottle_size_ml
 * @property float $total_mix_ml
 * @property int $expected_units
 * @property int $produced_units
 * @property float $total_cost
 * @property float $unit_cost
 * @property string|null $notes
 * @property int|null $produced_by_user_id
 * @property-read PosProduct|null $product
 * @property-read \Illuminate\Database\Eloquent\Collection<int, PosProductionLine> $lines
 */
final class PosProduction extends Model
{
    use \App\Models\Concerns\HasReference;

    protected $table = 'pos_productions';

    /** @var list<string> */
    protected $fillable = [
        'reference', 'pos_product_id', 'pos_session_id', 'bottle_size_ml',
        'total_mix_ml', 'expected_units', 'produced_units', 'total_cost',
        'unit_cost', 'notes', 'produced_by_user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pos_product_id' => 'integer',
            'pos_session_id' => 'integer',
            'bottle_size_ml' => 'float',
            'total_mix_ml' => 'float',
            'expected_units' => 'integer',
            'produced_units' => 'integer',
            'total_cost' => 'float',
            'unit_cost' => 'float',
            'produced_by_user_id' => 'integer',
        ];
    }

    public function referencePrefix(): string
    {
        return 'PRD';
    }

    /** Expected bottles from a mix of `$totalMl` at `$bottleMl` per bottle. */
    public static function expectedUnits(float $totalMl, float $bottleMl): int
    {
        return $bottleMl > 0 ? (int) floor($totalMl / $bottleMl) : 0;
    }

    /** Bottles short of the expected yield (the waste / loss alarm). */
    public function variance(): int
    {
        return max(0, $this->expected_units - $this->produced_units);
    }

    /**
     * @return BelongsTo<PosProduct, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(PosProduct::class, 'pos_product_id');
    }

    /**
     * @return HasMany<PosProductionLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PosProductionLine::class, 'pos_production_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function producedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'produced_by_user_id');
    }

    /**
     * Apply the run to stock: deduct each material's ML from ingredient stock
     * and add the produced bottles to the product's STORE stock. Call once,
     * after the lines have been created.
     */
    /**
     * How this run identifies itself in an ingredient's movement history.
     * Prefers the human reference when the run has one, else the id — the
     * ledger's `reference` is free text with no FK, so it must stay readable
     * even after the run itself is gone.
     */
    public function moveReference(): string
    {
        $reference = trim((string) ($this->reference ?? ''));

        return $reference !== '' ? "Production {$reference}" : 'Production #' . $this->getKey();
    }

    public function applyStock(): void
    {
        foreach ($this->lines as $line) {
            $ingredient = $line->ingredient;
            if ($ingredient === null) {
                continue;
            }
            if ($line->kind === PosProductionLine::KIND_PACKAGING) {
                // Per-bottle component: qty × bottles produced.
                $ingredient->applyStockDelta(
                    -((float) ($line->qty_per_unit ?? 0) * $this->produced_units),
                    IngredientMoveKind::Production,
                    $this->moveReference(),
                );
            } else {
                // converts ML → stock units
                $ingredient->deductMl((float) $line->ml_used, IngredientMoveKind::Production, $this->moveReference());
            }
        }

        $product = $this->product;
        if ($product !== null) {
            $product->store_stock = (float) $product->store_stock + $this->produced_units;
            // Give the finished perfume a real cost (material cost per bottle).
            if ($this->unit_cost > 0) {
                $product->cost_price = (float) $this->unit_cost;
            }
            $product->save();
        }
    }

    /**
     * Undo {@see applyStock()} — add the materials back and remove the produced
     * bottles from the store. Used before rewriting a production on edit.
     */
    public function reverseStock(): void
    {
        foreach ($this->lines as $line) {
            $ingredient = $line->ingredient;
            if ($ingredient === null) {
                continue;
            }
            // Reversal is recorded as a Production move too, just positive: the
            // history should show the run being undone, not silently rewind.
            if ($line->kind === PosProductionLine::KIND_PACKAGING) {
                $ingredient->applyStockDelta(
                    (float) ($line->qty_per_unit ?? 0) * $this->produced_units,
                    IngredientMoveKind::Production,
                    $this->moveReference() . ' (reversed)',
                );
            } else {
                $per = $ingredient->mlPerUnit();
                $ingredient->applyStockDelta(
                    $per > 0 ? (float) $line->ml_used / $per : (float) $line->ml_used,
                    IngredientMoveKind::Production,
                    $this->moveReference() . ' (reversed)',
                );
            }
        }

        $product = $this->product;
        if ($product !== null) {
            $product->store_stock = max(0.0, (float) $product->store_stock - $this->produced_units);
            $product->save();
        }
    }
}
