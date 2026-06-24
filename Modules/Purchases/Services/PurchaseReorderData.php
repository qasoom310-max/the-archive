<?php

declare(strict_types=1);

namespace Modules\Purchases\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Contacts\Models\Partner;
use Modules\Pos\Models\PosCondiment;
use Modules\Pos\Models\PosIngredient;
use Modules\Pos\Models\PosProduct;
use Modules\Pos\Models\PosProductRecipe;
use Modules\Pos\Services\PosStockReportData;
use Modules\Pos\Support\StockRow;
use Modules\Purchases\Enums\PurchaseState;
use Modules\Purchases\Support\ReorderRow;

/**
 * The buying team's shopping list: every **purchasable** item whose stock is at
 * or below its minimum (low or out), each tagged with the vendor to contact.
 *
 * Stock bucketing reuses {@see PosStockReportData} (per-item reorder point,
 * else the global threshold) — identical to the POS Stock Report — narrowed to
 * what the team can actually buy: ingredients + condiments + **resale** products
 * (no recipe; crafted products are assembled, not bought).
 *
 * Vendor per item = its **preferred supplier** (`supplier_id`) when set, else
 * the vendor on the most recent **confirmed** bill that bought it ("last bought
 * from"), else none.
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
     * Reorder rows (out first, then low) enriched with the vendor to contact.
     *
     * @return Collection<int, ReorderRow>
     */
    public function rows(string $search = ''): Collection
    {
        $stockRows = $this->purchasableStockRows($search);
        $vendors = $this->resolveVendors($stockRows);

        return $stockRows
            ->map(static function (StockRow $r) use ($vendors): ReorderRow {
                $vendor = $vendors[$r->type . ':' . $r->id] ?? null;

                return new ReorderRow(
                    item: $r,
                    vendorName: $vendor['name'] ?? null,
                    vendorPhone: $vendor['phone'] ?? null,
                );
            })
            ->values();
    }

    /**
     * @return array{total: int, low: int, out: int}
     */
    public function summary(string $search = ''): array
    {
        $rows = $this->purchasableStockRows($search);

        return [
            'total' => $rows->count(),
            'low' => $rows->where('status', 'low')->count(),
            'out' => $rows->where('status', 'out')->count(),
        ];
    }

    /**
     * Purchasable items needing a reorder (low/out), active only — the raw
     * stock rows before vendor enrichment.
     *
     * @return Collection<int, StockRow>
     */
    private function purchasableStockRows(string $search): Collection
    {
        $craftedProductIds = $this->craftedProductIds();

        return $this->stock->rows('', $search, false)
            ->filter(static fn (StockRow $r): bool => $r->status === 'low' || $r->status === 'out')
            ->reject(static fn (StockRow $r): bool => $r->isProduct() && in_array($r->id, $craftedProductIds, true))
            ->values();
    }

    /**
     * Resolve "type:id" => ['name' => ..., 'phone' => ...] for each row, taking
     * the preferred supplier when set and otherwise the last-bought vendor.
     *
     * @param  Collection<int, StockRow>  $stockRows
     * @return array<string, array{name: string, phone: string|null}>
     */
    private function resolveVendors(Collection $stockRows): array
    {
        if ($stockRows->isEmpty()) {
            return [];
        }

        $idsByType = [
            'product' => $stockRows->filter(static fn (StockRow $r): bool => $r->isProduct())->pluck('id')->all(),
            'ingredient' => $stockRows->filter(static fn (StockRow $r): bool => $r->isIngredient())->pluck('id')->all(),
            'condiment' => $stockRows->filter(static fn (StockRow $r): bool => $r->isCondiment())->pluck('id')->all(),
        ];

        $preferred = $this->preferredSuppliers($idsByType);
        $lastBought = $this->lastBoughtVendors($idsByType);

        // Resolve every referenced partner id to a name + phone in one query.
        $partnerIds = array_values(array_unique(array_merge(array_values($preferred), array_values($lastBought))));
        $partners = Partner::query()->whereIn('id', $partnerIds)->get(['id', 'name', 'phone'])->keyBy('id');

        $out = [];
        foreach ($stockRows as $r) {
            $key = $r->type . ':' . $r->id;
            $partnerId = $preferred[$key] ?? ($lastBought[$key] ?? null);

            if ($partnerId === null || ! $partners->has($partnerId)) {
                continue;
            }

            $partner = $partners->get($partnerId);
            $out[$key] = [
                'name' => (string) $partner->name,
                'phone' => $partner->phone !== null ? (string) $partner->phone : null,
            ];
        }

        return $out;
    }

    /**
     * Preferred supplier per item ("type:id" => partner id) — only rows that set one.
     *
     * @param  array{product: list<int>, ingredient: list<int>, condiment: list<int>}  $idsByType
     * @return array<string, int>
     */
    private function preferredSuppliers(array $idsByType): array
    {
        $map = [];

        $sources = [
            'product' => PosProduct::query(),
            'ingredient' => PosIngredient::query(),
            'condiment' => PosCondiment::query(),
        ];

        foreach ($sources as $type => $query) {
            if ($idsByType[$type] === []) {
                continue;
            }

            $pairs = $query->whereIn('id', $idsByType[$type])
                ->whereNotNull('supplier_id')
                ->pluck('supplier_id', 'id');

            foreach ($pairs as $id => $supplierId) {
                $map[$type . ':' . (int) $id] = (int) $supplierId;
            }
        }

        return $map;
    }

    /**
     * Last vendor each item was bought from on a confirmed bill ("type:id" =>
     * partner id). Walks confirmed purchase lines newest-first and keeps the
     * first vendor seen per item.
     *
     * @param  array{product: list<int>, ingredient: list<int>, condiment: list<int>}  $idsByType
     * @return array<string, int>
     */
    private function lastBoughtVendors(array $idsByType): array
    {
        $rows = DB::table('purchase_lines')
            ->join('purchases', 'purchases.id', '=', 'purchase_lines.purchase_id')
            ->where('purchases.state', PurchaseState::Confirmed->value)
            ->whereNotNull('purchases.partner_id')
            ->where(static function ($q) use ($idsByType): void {
                $q->whereIn('purchase_lines.pos_product_id', $idsByType['product'])
                    ->orWhereIn('purchase_lines.pos_condiment_id', $idsByType['condiment'])
                    ->orWhereIn('purchase_lines.pos_ingredient_id', $idsByType['ingredient']);
            })
            ->orderByDesc('purchases.date')
            ->orderByDesc('purchases.id')
            ->get([
                'purchase_lines.pos_product_id',
                'purchase_lines.pos_condiment_id',
                'purchase_lines.pos_ingredient_id',
                'purchases.partner_id',
            ]);

        $map = [];
        foreach ($rows as $row) {
            $r = (array) $row;
            $key = match (true) {
                $r['pos_product_id'] !== null => 'product:' . (int) $r['pos_product_id'],
                $r['pos_condiment_id'] !== null => 'condiment:' . (int) $r['pos_condiment_id'],
                $r['pos_ingredient_id'] !== null => 'ingredient:' . (int) $r['pos_ingredient_id'],
                default => null,
            };

            if ($key !== null && ! isset($map[$key])) {
                $map[$key] = (int) $r['partner_id'];
            }
        }

        return $map;
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
