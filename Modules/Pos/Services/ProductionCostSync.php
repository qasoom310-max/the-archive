<?php

declare(strict_types=1);

namespace Modules\Pos\Services;

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
 */
final class ProductionCostSync
{
    /** Recompute all made-in-house costs. Returns how many rows changed. */
    public function refreshAll(): int
    {
        $changed = 0;

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
                $changed += $this->apply($perfume, $cost);
            }
        }

        // Offers — those with a recipe (their line costs read live component costs).
        foreach (PosProduct::query()->whereHas('recipeLines')->get() as $offer) {
            $changed += $this->apply($offer, $offer->recipeCost());
        }

        return $changed;
    }

    /** Write the new cost only when it actually differs (quietly — no hooks). */
    private function apply(PosProduct $product, float $cost): int
    {
        $cost = round($cost, 4);
        if (abs((float) $product->cost_price - $cost) < 0.00005) {
            return 0;
        }

        $product->cost_price = $cost;
        $product->saveQuietly();

        return 1;
    }
}
