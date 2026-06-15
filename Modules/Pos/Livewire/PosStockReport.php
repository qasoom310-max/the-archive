<?php

declare(strict_types=1);

namespace Modules\Pos\Livewire;

use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Pos\Models\PosProduct;
use Modules\Pos\Services\DailyReport;

/**
 * POS Stock Report — an Odoo-style on-screen view of every product's stock
 * health: in stock, low (≤ the daily report's threshold), or out of stock.
 * Reuses {@see DailyReport::LOW_STOCK_THRESHOLD} so the screen and the 6 AM
 * emailed PDF always agree on what "low" means. Reached from the Inventory
 * Overview's "Products in stock" KPI card.
 */
#[Layout('components.layouts.app')]
#[Title('Stock Report')]
final class PosStockReport extends Component
{
    use WithPagination;

    /** '' = all · 'in' = in stock · 'low' = low stock · 'out' = out of stock. */
    #[Url(except: '')]
    public string $filter = '';

    #[Url(except: '')]
    public string $search = '';

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

    public function setFilter(string $filter): void
    {
        $this->filter = in_array($filter, ['in', 'low', 'out'], true) ? $filter : '';
        $this->resetPage();
    }

    public function render(): View
    {
        $threshold = DailyReport::LOW_STOCK_THRESHOLD;

        // Summary counts over the WHOLE catalogue (independent of the active
        // filter / search) so the chips always show the true totals. "In
        // stock" = stock > 0 — the same set the Inventory "Products in stock"
        // KPI counts, so the number the user clicked matches here.
        $summary = [
            'total' => PosProduct::query()->count(),
            'in' => PosProduct::query()->where('stock_on_hand', '>', 0)->count(),
            'low' => PosProduct::query()->where('stock_on_hand', '>', 0)
                ->where('stock_on_hand', '<=', $threshold)->count(),
            'out' => PosProduct::query()->where('stock_on_hand', '<=', 0)->count(),
        ];

        $products = PosProduct::query()
            ->with('category')
            ->when($this->filter === 'in', fn (Builder $q) => $q->where('stock_on_hand', '>', 0))
            ->when($this->filter === 'low', fn (Builder $q) => $q->where('stock_on_hand', '>', 0)->where('stock_on_hand', '<=', $threshold))
            ->when($this->filter === 'out', fn (Builder $q) => $q->where('stock_on_hand', '<=', 0))
            ->when($this->search !== '', fn (Builder $q) => $q->where(function (Builder $w): void {
                // `name` is a Spatie translatable JSON column — LIKE matches
                // the raw envelope, same pattern as the product list search.
                $w->where('name', 'like', '%' . $this->search . '%')
                    ->orWhere('barcode', $this->search);
            }))
            // Out-of-stock first, then low, then in-stock; lowest qty first
            // within each band so the items needing attention float up.
            ->orderByRaw('CASE WHEN stock_on_hand <= 0 THEN 0 WHEN stock_on_hand <= ? THEN 1 ELSE 2 END', [$threshold])
            ->orderBy('stock_on_hand')
            ->orderBy('id')
            ->paginate(30);

        return view('pos::stock-report', [
            'products' => $products,
            'summary' => $summary,
            'threshold' => $threshold,
        ]);
    }
}
