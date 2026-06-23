<?php

declare(strict_types=1);

namespace Modules\Pos\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Pos\Models\PosCondiment;
use Modules\Pos\Models\PosIngredient;
use Modules\Pos\Models\PosProduct;
use Modules\Pos\Support\StockRow;

/**
 * Single source of truth for the Stock Report's rows + summary, shared by the
 * on-screen component, the CSV export, and the printable view so all three
 * bucket items identically (in / low / out, per-product reorder point falling
 * back to the global threshold).
 *
 * Covers ALL stock-tracked catalogues — POS products, condiments / add-ons and
 * raw-material ingredients. Condiments have no reorder point / cost / barcode,
 * so they bucket against the global threshold and contribute 0 to valuation.
 * Ingredients carry a tracked cost (so they contribute to valuation) and a
 * unit, but no reorder point / barcode.
 */
final class PosStockReportData
{
    public function threshold(): float
    {
        return DailyReport::LOW_STOCK_THRESHOLD;
    }

    /**
     * Filtered, sorted stock rows (out → low → in, lowest qty first, then name).
     *
     * @return Collection<int, StockRow>
     */
    public function rows(string $filter, string $search, bool $includeInactive): Collection
    {
        $rows = $this->allRows($includeInactive);

        if ($search !== '') {
            $needle = mb_strtolower($search);
            $rows = $rows->filter(static fn (StockRow $r): bool => str_contains(mb_strtolower($r->name), $needle)
                || ($r->barcode !== null && $r->barcode === $search));
        }

        // Filter semantics mirror the chip counts: "in" = anything with stock
        // (low is a subset of in-stock, not exclusive of it).
        $rows = match ($filter) {
            'in' => $rows->filter(static fn (StockRow $r): bool => $r->stock > 0),
            'low' => $rows->filter(static fn (StockRow $r): bool => $r->status === 'low'),
            'out' => $rows->filter(static fn (StockRow $r): bool => $r->status === 'out'),
            default => $rows,
        };

        $rank = ['out' => 0, 'low' => 1, 'in' => 2];

        return $rows
            ->sort(static fn (StockRow $a, StockRow $b): int => [$rank[$a->status] ?? 3, $a->stock, mb_strtolower($a->name)]
                <=> [$rank[$b->status] ?? 3, $b->stock, mb_strtolower($b->name)])
            ->values();
    }

    /**
     * Catalogue-wide counts + total on-hand value (respecting the inactive
     * scope, ignoring search / filter — the summary chips show totals).
     *
     * @return array{total: int, in: int, low: int, out: int, value: float}
     */
    public function summary(bool $includeInactive): array
    {
        $rows = $this->allRows($includeInactive);

        // "in" counts everything with stock on hand (low items included — low
        // is a subset), so in + out = total and the chip math holds.
        return [
            'total' => $rows->count(),
            'in' => $rows->filter(static fn (StockRow $r): bool => $r->stock > 0)->count(),
            'low' => $rows->where('status', 'low')->count(),
            'out' => $rows->where('status', 'out')->count(),
            'value' => round((float) $rows->sum(static fn (StockRow $r): float => $r->value), 2),
        ];
    }

    /**
     * Every stock row within the active scope, each already bucketed — no
     * search / no status filter (those are applied by {@see rows()}).
     *
     * @return Collection<int, StockRow>
     */
    private function allRows(bool $includeInactive): Collection
    {
        $t = $this->threshold();

        $products = PosProduct::query()
            ->with('category')
            ->when(! $includeInactive, static fn (Builder $q) => $q->where('active', true))
            ->get()
            ->map(static function (PosProduct $p) use ($t): StockRow {
                $stock = (float) $p->stock_on_hand;
                $cost = (float) $p->cost_price;

                return new StockRow(
                    type: 'product',
                    id: (int) $p->id,
                    name: (string) $p->name,
                    category: $p->category_name,
                    stock: $stock,
                    unit: ($p->unit && $p->unit !== 'qty') ? (string) $p->unit : '',
                    cost: $cost,
                    value: $stock * $cost,
                    reorderPoint: $p->reorder_point !== null ? (float) $p->reorder_point : null,
                    status: $p->stockStatus($t),
                    active: (bool) $p->active,
                    barcode: ($p->barcode !== null && $p->barcode !== '') ? (string) $p->barcode : null,
                );
            });

        $condiments = PosCondiment::query()
            ->with('category')
            ->when(! $includeInactive, static fn (Builder $q) => $q->where('active', true))
            ->get()
            ->map(static function (PosCondiment $c) use ($t): StockRow {
                $stock = (float) $c->stock_on_hand;
                $min = $c->reorder_point !== null ? (float) $c->reorder_point : $t;
                $status = $stock <= 0 ? 'out' : ($stock <= $min ? 'low' : 'in');

                return new StockRow(
                    type: 'condiment',
                    id: (int) $c->id,
                    name: (string) $c->name,
                    category: $c->category_name,
                    stock: $stock,
                    unit: '',
                    cost: 0.0,         // condiment cost isn't tracked
                    value: 0.0,
                    reorderPoint: $c->reorder_point !== null ? (float) $c->reorder_point : null,
                    status: $status,
                    active: (bool) $c->active,
                    barcode: null,
                );
            });

        $ingredients = PosIngredient::query()
            ->when(! $includeInactive, static fn (Builder $q) => $q->where('active', true))
            ->get()
            ->map(static function (PosIngredient $i) use ($t): StockRow {
                $stock = (float) $i->stock_on_hand;
                $cost = (float) $i->cost_price;
                $min = $i->reorder_point !== null ? (float) $i->reorder_point : $t;
                $status = $stock <= 0 ? 'out' : ($stock <= $min ? 'low' : 'in');

                return new StockRow(
                    type: 'ingredient',
                    id: (int) $i->id,
                    name: (string) $i->name,
                    category: null,    // ingredients aren't category-scoped
                    stock: $stock,
                    unit: ($i->unit && $i->unit !== 'qty') ? (string) $i->unit : '',
                    cost: $cost,
                    value: $stock * $cost,
                    reorderPoint: $i->reorder_point !== null ? (float) $i->reorder_point : null,
                    status: $status,
                    active: (bool) $i->active,
                    barcode: null,
                );
            });

        return $products->concat($condiments)->concat($ingredients)->values();
    }
}
