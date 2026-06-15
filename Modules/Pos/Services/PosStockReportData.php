<?php

declare(strict_types=1);

namespace Modules\Pos\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Pos\Models\PosProduct;

/**
 * Single source of truth for the Stock Report's query + summary, shared by
 * the on-screen component, the CSV export, and the printable view so all
 * three bucket products identically (in / low / out, per-product reorder
 * point falling back to the global threshold).
 */
final class PosStockReportData
{
    /** SQL predicate for "low" — at or below the product's reorder point (or the global default). */
    private const LOW_EXPR = 'stock_on_hand <= COALESCE(reorder_point, ?)';

    public function threshold(): float
    {
        return DailyReport::LOW_STOCK_THRESHOLD;
    }

    /**
     * Filtered, sorted product query (out → low → in, lowest qty first).
     *
     * @return Builder<PosProduct>
     */
    public function query(string $filter, string $search, bool $includeInactive): Builder
    {
        $t = $this->threshold();

        return PosProduct::query()
            ->with('category')
            ->when(! $includeInactive, fn (Builder $q) => $q->where('active', true))
            ->when($filter === 'in', fn (Builder $q) => $q->where('stock_on_hand', '>', 0))
            ->when($filter === 'low', fn (Builder $q) => $q->where('stock_on_hand', '>', 0)->whereRaw(self::LOW_EXPR, [$t]))
            ->when($filter === 'out', fn (Builder $q) => $q->where('stock_on_hand', '<=', 0))
            ->when($search !== '', fn (Builder $q) => $q->where(function (Builder $w) use ($search): void {
                $w->where('name', 'like', '%' . $search . '%')->orWhere('barcode', $search);
            }))
            ->orderByRaw('CASE WHEN stock_on_hand <= 0 THEN 0 WHEN ' . self::LOW_EXPR . ' THEN 1 ELSE 2 END', [$t])
            ->orderBy('stock_on_hand')
            ->orderBy('id');
    }

    /**
     * Catalogue-wide counts + total on-hand value (respecting the inactive scope).
     *
     * @return array{total: int, in: int, low: int, out: int, value: float}
     */
    public function summary(bool $includeInactive): array
    {
        $t = $this->threshold();
        $base = fn (): Builder => PosProduct::query()
            ->when(! $includeInactive, fn (Builder $q) => $q->where('active', true));

        return [
            'total' => $base()->count(),
            'in' => $base()->where('stock_on_hand', '>', 0)->count(),
            'low' => $base()->where('stock_on_hand', '>', 0)->whereRaw(self::LOW_EXPR, [$t])->count(),
            'out' => $base()->where('stock_on_hand', '<=', 0)->count(),
            'value' => (float) $base()->sum(DB::raw('stock_on_hand * cost_price')),
        ];
    }

    /** 'in' | 'low' | 'out' for a product, using its reorder point. */
    public function status(PosProduct $product): string
    {
        return $product->stockStatus($this->threshold());
    }
}
