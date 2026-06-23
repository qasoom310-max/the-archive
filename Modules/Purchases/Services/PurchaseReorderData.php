<?php

declare(strict_types=1);

namespace Modules\Purchases\Services;

use Illuminate\Support\Collection;
use Modules\Pos\Models\PosProductRecipe;
use Modules\Pos\Services\PosStockReportData;
use Modules\Pos\Support\StockRow;

/**
 * The buying team's shopping list: every **purchasable** item whose stock is at
 * or below its minimum (low or out). Reuses {@see PosStockReportData} so the
 * in/low/out bucketing (per-product reorder point, else the global threshold)
 * is identical to the POS Stock Report — then narrows it to what the team can
 * actually buy:
 *
 *   - ingredients + condiments (always bought), and
 *   - **resale** products only (no recipe) — crafted products are assembled
 *     from their components, never purchased, so they're excluded (same rule
 *     as the purchase line picker).
 */
final class PurchaseReorderData
{
    public function __construct(private readonly PosStockReportData $stock)
    {
    }

    public function threshold(): float
    {
        return $this->stock->threshold();
    }

    /**
     * Purchasable items needing a reorder (out first, then low), active only.
     *
     * @return Collection<int, StockRow>
     */
    public function rows(string $search = ''): Collection
    {
        $craftedProductIds = $this->craftedProductIds();

        return $this->stock->rows('', $search, false)
            ->filter(static fn (StockRow $r): bool => $r->status === 'low' || $r->status === 'out')
            ->reject(static fn (StockRow $r): bool => $r->isProduct() && in_array($r->id, $craftedProductIds, true))
            ->values();
    }

    /**
     * @return array{total: int, low: int, out: int}
     */
    public function summary(string $search = ''): array
    {
        $rows = $this->rows($search);

        return [
            'total' => $rows->count(),
            'low' => $rows->where('status', 'low')->count(),
            'out' => $rows->where('status', 'out')->count(),
        ];
    }

    /**
     * Product ids that have a recipe (crafted — assembled, not purchased).
     *
     * @return list<int>
     */
    private function craftedProductIds(): array
    {
        return PosProductRecipe::query()
            ->whereNotNull('parent_product_id')
            ->distinct()
            ->pluck('parent_product_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }
}
