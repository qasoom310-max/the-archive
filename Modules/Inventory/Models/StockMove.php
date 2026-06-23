<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Enums\LocationType;
use Modules\Inventory\Enums\MoveState;

/**
 * Double-entry stock move: a transfer of `product_qty` of a product
 * from `source` to `destination`.
 *
 * @property int $id
 * @property string $reference
 * @property int|null $stock_operation_type_id
 * @property int|null $product_id
 * @property string $item_type  'product' (default) | 'ingredient' — what product_id references.
 * @property float $product_qty
 * @property int $source_location_id
 * @property int $dest_location_id
 * @property MoveState $state
 * @property string|null $lot_name
 * @property string|null $barcode
 * @property Carbon|null $scheduled_at
 * @property Carbon|null $done_at
 */
final class StockMove extends Model
{
    protected $table = 'stock_moves';

    /** @var list<string> */
    protected $fillable = [
        'reference', 'stock_operation_type_id', 'product_id', 'item_type', 'product_qty',
        'source_location_id', 'dest_location_id', 'state',
        'lot_name', 'barcode', 'scheduled_at', 'done_at',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'item_type' => 'product',
    ];

    /** A raw-material (ingredient) move — an audit-only entry, never process()ed. */
    public function isIngredient(): bool
    {
        return $this->item_type === 'ingredient';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'product_qty' => 'float',
            'state' => MoveState::class,
            'scheduled_at' => 'datetime',
            'done_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<StockOperationType, $this>
     */
    public function operationType(): BelongsTo
    {
        return $this->belongsTo(StockOperationType::class, 'stock_operation_type_id');
    }

    /**
     * @return BelongsTo<StockLocation, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'source_location_id');
    }

    /**
     * @return BelongsTo<StockLocation, $this>
     */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'dest_location_id');
    }

    /**
     * Double-entry valuation rule: stock value only changes when a
     * product crosses the internal boundary to/from a Customer or
     * Vendor location. Internal↔internal (and external↔external) moves
     * never change valuation.
     */
    public function affectsValuation(): bool
    {
        $src = $this->source?->type;
        $dest = $this->destination?->type;

        if ($src === null || $dest === null) {
            return false;
        }

        if ($src->isInternal() === $dest->isInternal()) {
            return false;
        }

        $external = $src->isInternal() ? $dest : $src;

        return $external === LocationType::Customer
            || $external === LocationType::Vendor;
    }

    /**
     * Validate the move: atomically debit the source quant and credit
     * the destination quant, then mark it Done. The whole transfer is
     * one DB transaction — a failure rolls back both sides and the
     * state change, so stock can never drift. Idempotent: a Done or
     * Cancelled move is a no-op.
     */
    public function process(): void
    {
        if ($this->state === MoveState::Done || $this->state === MoveState::Cancelled) {
            return;
        }

        DB::transaction(function (): void {
            if ($this->product_id !== null && $this->product_qty > 0) {
                $this->adjustQuant($this->source_location_id, -$this->product_qty);
                $this->adjustQuant($this->dest_location_id, $this->product_qty);
            }

            $this->state = MoveState::Done;
            $this->done_at = Carbon::now();
            $this->save();
        });
    }

    /**
     * Move on-hand at one location by $delta (negative = out). Runs
     * inside the caller's transaction, so both legs commit or roll back
     * together. (Row-level `lockForUpdate` is a production refinement
     * for MySQL/PostgreSQL; SQLite serialises writes anyway.)
     */
    private function adjustQuant(int $locationId, float $delta): void
    {
        $quant = StockQuant::query()->firstOrNew([
            'stock_location_id' => $locationId,
            'product_id' => $this->product_id,
            'lot_name' => $this->lot_name,
        ]);

        $quant->quantity = round(($quant->exists ? $quant->quantity : 0.0) + $delta, 3);
        $quant->save();
    }
}
