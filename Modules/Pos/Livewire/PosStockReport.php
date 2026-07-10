<?php

declare(strict_types=1);

namespace Modules\Pos\Livewire;

use App\Erp\Business\Feature;
use App\Erp\Business\Features;
use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Pos\Models\PosCondiment;
use Modules\Pos\Models\PosIngredient;
use Modules\Pos\Models\PosProduct;
use Modules\Pos\Services\PosStockReportData;

/**
 * POS Stock Report — an Odoo-style on-screen view of every product's stock
 * health: in stock, low (≤ its reorder point, or the daily report's global
 * threshold), or out of stock. Reuses {@see DailyReport::LOW_STOCK_THRESHOLD}
 * as the global fallback so the screen and the 6 AM emailed PDF agree.
 *
 * Also surfaces stock valuation (on-hand × cost), an inline "Adjust" restock
 * action, an inactive-products toggle, and CSV / print export. Reached from
 * the Inventory Overview's "Products in stock" KPI card.
 */
#[Layout('components.layouts.app')]
#[Title('Stock Report')]
final class PosStockReport extends Component
{
    use WithPagination;

    /** '' = all · 'in' · 'low' · 'out'. */
    #[Url(except: '')]
    public string $filter = '';

    #[Url(except: '')]
    public string $search = '';

    /**
     * When Production is enabled (perfumes POS), the report splits into two
     * inventories: 'products' (finished goods for sale) and 'materials' (raw
     * materials used in production). Ignored when Production is off.
     */
    #[Url(except: 'products')]
    public string $scope = 'products';

    /** Include discontinued (inactive) products. Default: active only. */
    #[Url(except: false)]
    public bool $includeInactive = false;

    /** Inline restock modal: the item being adjusted + its new on-hand. */
    public ?int $adjustId = null;

    /** 'product' | 'condiment' | 'ingredient' — which catalogue the adjusted row belongs to. */
    public string $adjustType = 'product';

    public string $adjustQty = '';

    private const PER_PAGE = 30;

    public function mount(): void
    {
        app(AccessControl::class)->authorize(Auth::user(), 'pos.product', Permission::Read);
    }

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function setScope(string $scope): void
    {
        $this->scope = $scope === 'materials' ? 'materials' : 'products';
        $this->resetPage();
    }

    public function updatedIncludeInactive(): void
    {
        $this->resetPage();
    }

    public function setFilter(string $filter): void
    {
        $this->filter = in_array($filter, ['in', 'low', 'out'], true) ? $filter : '';
        $this->resetPage();
    }

    public function openAdjust(int $id, string $type = 'product'): void
    {
        $type = in_array($type, ['condiment', 'ingredient'], true) ? $type : 'product';
        $model = $this->findStockModel($id, $type);
        if ($model === null) {
            return;
        }

        $this->adjustId = $id;
        $this->adjustType = $type;
        $this->adjustQty = rtrim(rtrim(number_format((float) $model->stock_on_hand, 3, '.', ''), '0'), '.');
    }

    public function closeAdjust(): void
    {
        $this->adjustId = null;
        $this->adjustType = 'product';
        $this->adjustQty = '';
    }

    /**
     * Set the item's on-hand to the typed value. Write-gated; a saved product
     * fires the Inventory-sync hook so the ledger tracks the change.
     */
    public function saveAdjust(): void
    {
        app(AccessControl::class)->authorize(Auth::user(), 'pos.product', Permission::Write);

        if ($this->adjustId === null) {
            return;
        }

        $model = $this->findStockModel($this->adjustId, $this->adjustType);
        if ($model === null) {
            $this->closeAdjust();

            return;
        }

        $model->stock_on_hand = max(0.0, round((float) $this->adjustQty, 3));
        $model->save();

        $this->closeAdjust();
    }

    /** Resolve the adjusted row's underlying model (product, condiment or ingredient). */
    private function findStockModel(int $id, string $type): PosProduct|PosCondiment|PosIngredient|null
    {
        return match ($type) {
            'condiment' => PosCondiment::query()->find($id),
            'ingredient' => PosIngredient::query()->find($id),
            default => PosProduct::query()->find($id),
        };
    }

    public function render(): View
    {
        $data = app(PosStockReportData::class);
        $productionOn = Features::enabled(Feature::Production);

        // Perfumes POS splits the catalogue into two inventories: finished
        // products vs raw production materials (ingredients). The summary chips
        // and value are recomputed for the active scope so they add up.
        if ($productionOn) {
            $all = $data->rows('', $this->search, $this->includeInactive)
                ->filter(fn (\Modules\Pos\Support\StockRow $r): bool => $this->scope === 'materials' ? $r->isIngredient() : ! $r->isIngredient())
                ->values();

            $summary = [
                'total' => $all->count(),
                'in' => $all->filter(fn (\Modules\Pos\Support\StockRow $r): bool => $r->stock > 0)->count(),
                'low' => $all->filter(fn (\Modules\Pos\Support\StockRow $r): bool => $r->status === 'low')->count(),
                'out' => $all->filter(fn (\Modules\Pos\Support\StockRow $r): bool => $r->status === 'out')->count(),
                'value' => round((float) $all->sum(fn (\Modules\Pos\Support\StockRow $r): float => $r->value), 2),
            ];

            $rows = (match ($this->filter) {
                'in' => $all->filter(fn (\Modules\Pos\Support\StockRow $r): bool => $r->stock > 0),
                'low' => $all->filter(fn (\Modules\Pos\Support\StockRow $r): bool => $r->status === 'low'),
                'out' => $all->filter(fn (\Modules\Pos\Support\StockRow $r): bool => $r->status === 'out'),
                default => $all,
            })->values();
        } else {
            $rows = $data->rows($this->filter, $this->search, $this->includeInactive);
            $summary = $data->summary($this->includeInactive);
        }

        $page = Paginator::resolveCurrentPage();
        $paginator = new LengthAwarePaginator(
            $rows->forPage($page, self::PER_PAGE)->values(),
            $rows->count(),
            self::PER_PAGE,
            $page,
            ['path' => Paginator::resolveCurrentPath()],
        );

        $adjust = $this->adjustId !== null ? $this->findStockModel($this->adjustId, $this->adjustType) : null;

        return view('pos::stock-report', [
            'rows' => $paginator,
            'summary' => $summary,
            'threshold' => $data->threshold(),
            'purchasesInstalled' => Schema::hasTable('purchases'),
            'adjustName' => $adjust !== null ? (string) $adjust->name : null,
            'showScope' => $productionOn,
            'scope' => $this->scope,
        ]);
    }
}
