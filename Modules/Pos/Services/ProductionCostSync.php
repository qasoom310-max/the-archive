<?php

declare(strict_types=1);

namespace Modules\Pos\Services;

use Illuminate\Support\Facades\DB;
use Modules\Pos\Models\PosProduct;
use Modules\Pos\Models\PosProduction;

/**
 * Re-derive every made-in-house product's stored `cost_price` from CURRENT
 * material prices, so a corrected material cost (e.g. a mistyped ethanol price)
 * flows through to the perfumes and offers that depend on it.
 *
 * - A **perfume** (has a production run) costs what {@see PosProduct::productionCost()}
 *   recomputes from its latest run at today's ingredient prices.
 * - An **offer** (has a recipe) costs what {@see PosProduct::recipeCost()} sums —
 *   and each recipe line already reads its perfume component's LIVE production
 *   cost, so offers self-correct off the same corrected material price.
 *
 * Idempotent: it only writes a row whose stored cost actually differs, and
 * writes quietly so it never triggers unrelated save-hooks or loops. Runs both
 * on demand (the "Recompute costs" button) and automatically whenever a
 * material's cost changes.
 *
 * Performance: this runs SYNCHRONOUSLY inside the ingredient-save request (and
 * the engine autosaves per keystroke), so it must be cheap. All the costs are
 * computed with READS first — holding no lock — then only the rows that changed
 * are written inside a SINGLE transaction. On SQLite (each tenant workspace is
 * one file) that means one brief write lock instead of one per product; the
 * previous per-row `saveQuietly()` loop could hold the writer long enough for a
 * busy register to push the request past nginx's timeout (a 504).
 */
final class ProductionCostSync
{
    /** Recompute all made-in-house costs. Returns how many rows changed. */
    public function refreshAll(): int
    {
        // Pass 1 — compute every target cost with reads only (no write lock).
        // [productId => [product, newCost]]
        $targets = [];

        // Perfumes — those with at least one COMPLETED run. A perfume whose only
        // runs are reversed/draft has no real batch to cost from, so it's left
        // alone (its cost stays whatever its last Done run or manual price set).
        $producedIds = PosProduction::query()
            ->where('state', PosProduction::STATE_DONE)
            ->distinct()
            ->pluck('pos_product_id')
            ->all();
        foreach (PosProduct::query()->whereIn('id', $producedIds)->get() as $perfume) {
            $cost = $perfume->productionCost();
            if ($cost !== null) {
                $targets[$perfume->getKey()] = [$perfume, round($cost, 4)];
            }
        }

        // Offers — those with a recipe (their line costs read live component costs).
        foreach (PosProduct::query()->whereHas('recipeLines')->get() as $offer) {
            $targets[$offer->getKey()] = [$offer, round($offer->recipeCost(), 4)];
        }

        // Keep only the rows whose stored cost actually moved.
        $dirty = array_filter(
            $targets,
            static fn (array $t): bool => abs((float) $t[0]->cost_price - $t[1]) >= 0.00005,
        );

        if ($dirty === []) {
            return 0;
        }

        // Pass 2 — write them all in one short transaction (one SQLite lock).
        DB::transaction(static function () use ($dirty): void {
            foreach ($dirty as [$product, $cost]) {
                $product->cost_price = $cost;
                $product->saveQuietly();
            }
        });

        return count($dirty);
    }
}
