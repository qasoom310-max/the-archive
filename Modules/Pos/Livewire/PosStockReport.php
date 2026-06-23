<?php

declare(strict_types=1);

namespace Modules\Pos\Livewire;

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

    /** Include discontinued (inactive) products. Default: active only. */
    #[Url(except: false)]
    public bool $includeInactive = false;

    /** Inline restock modal: the item being adjusted + its new on-hand. */
    public ?int $adjustId = null;

    /** 'product' | 'condiment' — which catalogue the adjusted row belongs to. */
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
        $type = $type === 'condiment' ? 'condiment' : 'product';
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

    /** Resolve the adjusted row's underlying model (product or condiment). */
    private function findStockModel(int $id, string $type): PosProduct|PosCondiment|null
    {
        return $type === 'condiment'
            ? PosCondiment::query()->find($id)
            : PosProduct::query()->find($id);
    }

    public function render(): View
    {
        $data = app(PosStockReportData::class);

        // Products + condiments are merged in PHP, so paginate the resulting
        // collection by hand into a LengthAwarePaginator the compact links
        // partial understands.
        $rows = $data->rows($this->filter, $this->search, $this->includeInactive);
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
            'summary' => $data->summary($this->includeInactive),
            'threshold' => $data->threshold(),
            'purchasesInstalled' => Schema::hasTable('purchases'),
            'adjustName' => $adjust !== null ? (string) $adjust->name : null,
        ]);
    }
}
