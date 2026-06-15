<?php

declare(strict_types=1);

namespace Modules\Pos\Livewire;

use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
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

    /** Inline restock modal: the product being adjusted + its new on-hand. */
    public ?int $adjustId = null;

    public string $adjustQty = '';

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

    public function openAdjust(int $productId): void
    {
        $product = PosProduct::query()->find($productId);
        if ($product === null) {
            return;
        }

        $this->adjustId = $productId;
        $this->adjustQty = rtrim(rtrim(number_format((float) $product->stock_on_hand, 3, '.', ''), '0'), '.');
    }

    public function closeAdjust(): void
    {
        $this->adjustId = null;
        $this->adjustQty = '';
    }

    /**
     * Set a product's on-hand to the typed value. Write-gated; the saved
     * model fires the Inventory-sync hook so the ledger tracks the change.
     */
    public function saveAdjust(): void
    {
        app(AccessControl::class)->authorize(Auth::user(), 'pos.product', Permission::Write);

        if ($this->adjustId === null) {
            return;
        }

        $product = PosProduct::query()->find($this->adjustId);
        if ($product === null) {
            $this->closeAdjust();

            return;
        }

        $product->stock_on_hand = max(0.0, round((float) $this->adjustQty, 3));
        $product->save();

        $this->closeAdjust();
    }

    public function render(): View
    {
        $data = app(PosStockReportData::class);

        return view('pos::stock-report', [
            'products' => $data->query($this->filter, $this->search, $this->includeInactive)->paginate(30),
            'summary' => $data->summary($this->includeInactive),
            'threshold' => $data->threshold(),
            'purchasesInstalled' => Schema::hasTable('purchases'),
            'adjustProduct' => $this->adjustId !== null ? PosProduct::query()->find($this->adjustId) : null,
        ]);
    }
}
