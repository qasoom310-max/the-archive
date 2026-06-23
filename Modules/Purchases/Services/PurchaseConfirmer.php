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
use Modules\Pos\Models\PosCondiment;
use Modules\Pos\Models\PosIngredient;
use Modules\Pos\Models\PosProduct;
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

            foreach ($purchase->lines as $line) {
                if ($line->quantity <= 0) {
                    continue;
                }

                // A line buys EITHER a product, a condiment or an ingredient. A
                // product also posts a warehouse receipt; condiments and
                // ingredients only raise their own on-hand (neither is on the
                // Inventory ledger — same as recipe consumption).
                if ($line->pos_product_id !== null) {
                    $this->raisePosStock($line);
                    $this->receiveIntoWarehouse($purchase, $line);
                } elseif ($line->pos_condiment_id !== null) {
                    $this->raiseCondimentStock($line);
                } elseif ($line->pos_ingredient_id !== null) {
                    $this->raiseIngredientStock($line);
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

        $ingredient->stock_on_hand = round((float) $ingredient->stock_on_hand + (float) $line->quantity, 3);
        $ingredient->save();
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
