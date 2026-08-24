<?php

declare(strict_types=1);

namespace Modules\Purchases\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Inventory\Enums\LocationType;
use Modules\Inventory\Enums\MoveState;
use Modules\Inventory\Models\StockLocation;
use Modules\Inventory\Models\StockMove;
use Modules\Inventory\Models\StockOperationType;
use Modules\Pos\Enums\IngredientMoveKind;
use Modules\Pos\Models\PosCondiment;
use Modules\Pos\Models\PosIngredient;
use Modules\Pos\Models\PosProduct;
use Modules\Pos\Services\PosInventoryBridge;
use Modules\Purchases\Enums\PurchaseState;
use Modules\Purchases\Events\PurchaseInvoiceConfirmed;
use Modules\Purchases\Models\Purchase;
use Modules\Purchases\Models\PurchaseLine;
use Throwable;

/**
 * The single entry point for confirming a vendor bill. Confirming is the
 * irreversible moment that makes a purchase "real": for every line it
 *
 *   1. raises the POS product's `stock_on_hand`, and
 *   2. posts a warehouse **receipt** move (Vendor → Stock) into Inventory,
 *      updating the `stock_quants` keyed by the SAME product id —
 *
 * so the POS tile count and the Inventory on-hand figure stay in lock-step.
 * Steps 1-2 plus the state flip are one DB transaction (all-or-nothing).
 * Accounting is posted afterwards, best-effort, via
 * {@see PurchaseInvoiceConfirmed} — a missing chart-of-accounts row must
 * never undo a confirmed bill (same convention as the POS receipt/journal
 * listeners).
 *
 * Idempotent: confirming an already-confirmed bill is a no-op.
 */
final class PurchaseConfirmer
{
    public function confirm(Purchase $purchase): Purchase
    {
        if ($purchase->state === PurchaseState::Confirmed) {
            return $purchase;
        }

        $purchase->loadMissing('lines');

        DB::transaction(function () use ($purchase): void {
            $purchase->recomputeTotal();
            $purchase->state = PurchaseState::Confirmed;
            $purchase->confirmed_at = Carbon::now();

            $userId = Auth::id();
            if ($userId !== null) {
                $purchase->user_id = (int) $userId;
            }

            $purchase->save();

            // Fold the delivery cost into each line's landed unit cost (split by
            // value) BEFORE stock is valued, so a material's cost price reflects
            // what it truly cost to land here — not just the invoice unit price.
            $this->applyLandedCosts($purchase);

            foreach ($purchase->lines as $line) {
                if ($line->quantity <= 0) {
                    continue;
                }

                // A line buys EITHER a product, a condiment or an ingredient. A
                // product posts a warehouse receipt that updates the quant
                // ledger; an ingredient raises its own on-hand AND posts a Done
                // ingredient receipt move for the Inventory dashboard (audit
                // only — it never touches the product-keyed quants). A condiment
                // just raises its on-hand (not surfaced in Inventory).
                if ($line->pos_product_id !== null) {
                    // The warehouse receipt below IS this line's quant
                    // movement. Raising the product's on-hand also fires the
                    // POS→Inventory mirror (PosProduct::saved), which would
                    // apply the SAME quantity to the same quant a second time
                    // — 4 on hand + a 10 kg bill showed 24, not 14. Suppress
                    // only the mirror; other saved-listeners still run.
                    PosInventoryBridge::withoutQuantSync(function () use ($line): void {
                        $this->raisePosStock($line);
                    });
                    $this->receiveIntoWarehouse($purchase, $line);
                } elseif ($line->pos_condiment_id !== null) {
                    $this->raiseCondimentStock($line);
                } elseif ($line->pos_ingredient_id !== null) {
                    $this->raiseIngredientStock($line);
                    app(PosInventoryBridge::class)->recordIngredientReceipt(
                        (int) $line->pos_ingredient_id,
                        (float) $line->quantity,
                        (string) $purchase->reference,
                    );
                }
            }
        });

        // Fire AFTER the stock transaction commits — mirrors
        // PosOrder::finalizeSale → PosOrderPaid. The accounting listener
        // rethrows on a misconfigured COA; we swallow it here and leave a
        // breadcrumb on the bill's Chatter rather than rolling back the
        // already-received stock.
        try {
            event(new PurchaseInvoiceConfirmed($purchase));
        } catch (Throwable $e) {
            $purchase->logChange('Accounting posting failed: ' . $e->getMessage());
        }

        return $purchase;
    }

    /**
     * Fold the purchase's delivery cost into each line's `landed_unit_cost`,
     * split BY VALUE (a line that is a bigger share of the goods total absorbs
     * a bigger share of the delivery). With no delivery — or when every line is
     * free (goods total 0) — the landed cost is just the unit cost.
     */
    private function applyLandedCosts(Purchase $purchase): void
    {
        $delivery = round((float) $purchase->delivery_cost, 2);
        $goodsTotal = round((float) $purchase->lines->sum('subtotal'), 2);

        foreach ($purchase->lines as $line) {
            $qty = (float) $line->quantity;
            $share = ($delivery > 0.0 && $goodsTotal > 0.0)
                ? $delivery * ((float) $line->subtotal / $goodsTotal)
                : 0.0;
            $perUnit = $qty > 0.0 ? $share / $qty : 0.0;

            $line->landed_unit_cost = round((float) $line->unit_cost + $perUnit, 4);
            $line->save();
        }
    }

    /**
     * Increase the catalogue product's on-hand count. This is the POS side
     * of the sync — the same figure the terminal tiles and KDS yield checks
     * read.
     */
    private function raisePosStock(PurchaseLine $line): void
    {
        $product = PosProduct::query()->find($line->pos_product_id);

        if ($product === null) {
            return;
        }

        $product->stock_on_hand = round((float) $product->stock_on_hand + (float) $line->quantity, 3);
        $product->save();
    }

    /**
     * Increase a condiment's on-hand count. Condiments are stock-tracked (so
     * they can be recipe components) but are NOT on the Inventory warehouse
     * ledger, so there's no receipt move — only the POS-side figure.
     */
    private function raiseCondimentStock(PurchaseLine $line): void
    {
        $condiment = PosCondiment::query()->find($line->pos_condiment_id);

        if ($condiment === null) {
            return;
        }

        $condiment->stock_on_hand = round((float) $condiment->stock_on_hand + (float) $line->quantity, 3);
        $condiment->save();
    }

    /**
     * Increase an ingredient's on-hand count. Like condiments, ingredients are
     * stock-tracked (so they can be recipe components) but are NOT on the
     * Inventory warehouse ledger, so there's no receipt move — only the
     * POS-side figure.
     */
    private function raiseIngredientStock(PurchaseLine $line): void
    {
        $ingredient = PosIngredient::query()->find($line->pos_ingredient_id);

        if ($ingredient === null) {
            return;
        }

        // Adopt the LANDED cost (invoice unit price + this line's delivery
        // share) as the material's cost, so a material bought for the first
        // time stops valuing at 0 and its cost reflects the real landed price.
        $cost = $line->effectiveUnitCost();
        if ($cost > 0) {
            $ingredient->cost_price = $cost;
            $ingredient->save();
        }

        // The quantity goes through applyStockDelta so the receipt is recorded
        // in the material's movement history — this is the only thing that
        // counts toward its "purchased" total.
        $ingredient->applyStockDelta(
            (float) $line->quantity,
            IngredientMoveKind::Purchase,
            // Larastan reads the magic relation accessor as non-null, so `?->`
            // is rejected here — same pattern as PosOrder::getProcessedByAttribute.
            trim('Purchase ' . (string) ($line->purchase->reference ?? '')),
        );
    }

    /**
     * Post a warehouse receipt (Vendor location → internal Stock location)
     * for the line, keyed by the POS product id, then validate it so the
     * `stock_quants` row is updated. Defensive: if the Inventory module
     * isn't installed or its warehouse topology hasn't been seeded, the POS
     * leg has still synced — we log the skip and move on.
     */
    private function receiveIntoWarehouse(Purchase $purchase, PurchaseLine $line): void
    {
        if (! Schema::hasTable('stock_moves') || ! Schema::hasTable('stock_locations')) {
            return;
        }

        // Prefer the Receipt operation type's configured Vendor → Stock
        // locations; fall back to the canonical types if it isn't set up.
        // `value()` returns a nullable scalar (no object nullsafe needed).
        $sourceId = StockOperationType::query()->where('code', 'incoming')->value('default_source_location_id')
            ?? StockLocation::query()->where('type', LocationType::Vendor->value)->value('id');
        $destId = StockOperationType::query()->where('code', 'incoming')->value('default_dest_location_id')
            ?? StockLocation::query()->where('type', LocationType::Internal->value)->value('id');

        if ($sourceId === null || $destId === null) {
            $purchase->logChange(
                "Warehouse receipt skipped for '{$line->description}' — Inventory locations are not set up (run InventorySeeder). POS stock was still updated."
            );

            return;
        }

        $receiptId = StockOperationType::query()->where('code', 'incoming')->value('id');

        $move = StockMove::query()->create([
            'reference' => (string) $purchase->reference,
            'stock_operation_type_id' => $receiptId === null ? null : (int) $receiptId,
            'product_id' => $line->pos_product_id,
            'product_qty' => (float) $line->quantity,
            'source_location_id' => (int) $sourceId,
            'dest_location_id' => (int) $destId,
            'state' => MoveState::Draft->value,
        ]);

        // Validate immediately: debits the Vendor quant, credits the Stock
        // quant, marks the move Done — one atomic step inside this method.
        $move->process();
    }
}
