<?php

declare(strict_types=1);

namespace Modules\Pos\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Inventory\Enums\LocationType;
use Modules\Inventory\Enums\MoveState;
use Modules\Inventory\Models\StockLocation;
use Modules\Inventory\Models\StockMove;
use Modules\Inventory\Models\StockOperationType;
use Modules\Inventory\Models\StockQuant;

/**
 * Keeps the double-entry Inventory ledger in step with POS product stock in
 * real time. Whenever a POS product's `stock_on_hand` changes — a manual
 * stock edit, the Stock Report's "Adjust", or ingredient consumption on a
 * sale — {@see sync()} mirrors the new quantity onto the product's quant at
 * the main internal Stock location and records a Done adjustment move for the
 * Inventory dashboard's audit trail.
 *
 * Fully decoupled + defensive: Inventory is referenced only through
 * `Schema::hasTable()` guards and runtime model lookups, so POS keeps working
 * (and this is a silent no-op) when the Inventory module isn't installed or
 * its warehouse topology hasn't been seeded.
 */
final class PosInventoryBridge
{
    /**
     * Mirror `$targetQty` onto the product's on-hand quant at the main Stock
     * location, posting a Done adjustment move for the delta. No-op when
     * Inventory is absent, there's no internal location, or nothing changed.
     */
    public function sync(int $productId, float $targetQty, string $reason): void
    {
        if (! Schema::hasTable('stock_quants') || ! Schema::hasTable('stock_locations')) {
            return;
        }

        $stockLocation = $this->mainStockLocation();
        if ($stockLocation === null) {
            return;
        }

        $quant = StockQuant::query()->firstOrNew([
            'stock_location_id' => $stockLocation->id,
            'product_id' => $productId,
            'lot_name' => null,
        ]);

        $current = $quant->exists ? (float) $quant->quantity : 0.0;
        $target = round($targetQty, 3);
        $delta = round($target - $current, 3);

        if (abs($delta) < 0.0001) {
            return;
        }

        DB::transaction(function () use ($quant, $target, $delta, $productId, $stockLocation, $reason): void {
            $quant->quantity = $target;
            $quant->save();

            $this->recordAdjustmentMove($productId, $delta, $stockLocation, $reason);
        });
    }

    /**
     * Record an ingredient **receipt** (Vendor → Stock) as a Done move for the
     * Inventory dashboard's audit trail when a purchase of a raw material is
     * confirmed. Ingredients aren't on the product-keyed quant ledger, so this
     * is recorded directly as Done and is NEVER process()ed — it never touches
     * `stock_quants`. The ingredient's authoritative on-hand stays in
     * `pos_ingredients`. No-op when Inventory / its locations are absent.
     */
    public function recordIngredientReceipt(int $ingredientId, float $qty, string $reference): void
    {
        if ($qty <= 0 || ! Schema::hasTable('stock_locations')) {
            return;
        }

        $stock = $this->mainStockLocation();
        $vendor = $this->firstLocationOfType(LocationType::Vendor);

        if ($stock === null || $vendor === null) {
            return;
        }

        $incomingId = StockOperationType::query()->where('code', 'incoming')->value('id');

        $this->recordIngredientMove(
            $ingredientId,
            round($qty, 3),
            $vendor,
            $stock,
            $reference,
            $incomingId === null ? null : (int) $incomingId,
        );
    }

    /**
     * Record ingredient **consumption** (Stock → Inventory) as a Done move when
     * a sold product's recipe uses up a raw material. Audit-only (no quant
     * mutation), mirroring how consumed product-components post an adjustment
     * move. No-op when Inventory / its locations are absent.
     */
    public function recordIngredientConsumption(int $ingredientId, float $qty, string $reference): void
    {
        if ($qty <= 0 || ! Schema::hasTable('stock_locations')) {
            return;
        }

        $stock = $this->mainStockLocation();
        $counterpart = $this->firstLocationOfType(LocationType::Inventory);

        if ($stock === null || $counterpart === null) {
            return;
        }

        $this->recordIngredientMove($ingredientId, round($qty, 3), $stock, $counterpart, $reference, null);
    }

    /**
     * Create the Done ingredient move (item_type = 'ingredient'). Guarded on
     * the tables so it is a silent no-op without Inventory. Crucially this does
     * NOT call process() — the product-keyed `stock_quants` is left untouched.
     */
    private function recordIngredientMove(int $ingredientId, float $qty, StockLocation $source, StockLocation $dest, string $reference, ?int $operationTypeId): void
    {
        if (! Schema::hasTable('stock_moves')) {
            return;
        }

        StockMove::query()->create([
            'reference' => $reference,
            'stock_operation_type_id' => $operationTypeId,
            'product_id' => $ingredientId,
            'item_type' => 'ingredient',
            'product_qty' => $qty,
            'source_location_id' => $source->id,
            'dest_location_id' => $dest->id,
            'state' => MoveState::Done->value,
            'done_at' => Carbon::now(),
        ]);
    }

    private function firstLocationOfType(LocationType $type): ?StockLocation
    {
        return StockLocation::query()
            ->where('type', $type->value)
            ->orderBy('id')
            ->first();
    }

    /**
     * The internal location stock lives at — prefer one literally named
     * "Stock", else the first active internal location.
     */
    private function mainStockLocation(): ?StockLocation
    {
        return StockLocation::query()
            ->where('type', LocationType::Internal->value)
            ->where('active', true)
            ->orderByRaw("CASE WHEN name = 'Stock' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->first();
    }

    /**
     * Audit move for the adjustment delta (counterpart = an Inventory-type
     * location, the Odoo "Inventory adjustment" virtual location). Recorded
     * directly as Done — the quant is already set authoritatively above, so
     * we must NOT call process() (that would double-apply). Skipped silently
     * if no counterpart location exists.
     */
    private function recordAdjustmentMove(int $productId, float $delta, StockLocation $stock, string $reason): void
    {
        $counterpart = StockLocation::query()
            ->where('type', LocationType::Inventory->value)
            ->orderBy('id')
            ->first();

        if ($counterpart === null) {
            return;
        }

        // delta > 0 → stock increased (Inventory → Stock); < 0 → decreased.
        [$source, $dest] = $delta > 0 ? [$counterpart, $stock] : [$stock, $counterpart];

        StockMove::query()->create([
            'reference' => $reason,
            'product_id' => $productId,
            'product_qty' => abs($delta),
            'source_location_id' => $source->id,
            'dest_location_id' => $dest->id,
            'state' => MoveState::Done->value,
            'done_at' => Carbon::now(),
        ]);
    }
}
