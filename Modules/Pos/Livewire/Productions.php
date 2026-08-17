<?php

declare(strict_types=1);

namespace Modules\Pos\Livewire;

use App\Erp\Business\Feature;
use App\Erp\Business\Features;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Pos\Models\PosProduct;
use Modules\Pos\Models\PosProduction;
use Modules\Pos\Models\PosStockTransfer;
use Modules\Pos\Services\PosSessionManager;

/**
 * Production & store home: move finished stock from the store to the shop, and
 * review the production and transfer history. Perfumes POS only.
 */
#[Layout('components.layouts.app')]
#[Title('Production & store')]
final class Productions extends Component
{
    public ?int $move_product_id = null;

    public string $move_qty = '';

    /** Optional: which production run the moved bottles came from. */
    public ?int $move_production_id = null;

    public ?int $back_product_id = null;

    public string $back_qty = '';

    public ?int $remove_product_id = null;

    public string $remove_qty = '';

    public function mount(): void
    {
        abort_unless(Features::enabled(Feature::Production), 404);
    }

    /**
     * Re-derive every perfume + offer cost from CURRENT material prices — the
     * one-click cleanup after a mistyped material cost (e.g. ethanol) is fixed.
     * Admin-only; also runs automatically when a material's cost changes.
     */
    public function recomputeCosts(): void
    {
        abort_unless(Features::enabled(Feature::Production), 404);
        abort_unless(Auth::user()?->isAdmin() === true, 403);

        $changed = app(\Modules\Pos\Services\ProductionCostSync::class)->refreshAll();
        app(\App\Erp\Activity\ActivityLogger::class)->log('updated', __('Product costs'), __('Recomputed :n product costs from current material prices.', ['n' => $changed]));

        session()->flash('toast', __(':n product costs recomputed from current material prices.', ['n' => $changed]));
    }

    /** Reset the production tag whenever the product changes (its runs differ). */
    public function updatedMoveProductId(): void
    {
        $this->move_production_id = null;
    }

    public function moveToShop(): void
    {
        abort_unless(Features::enabled(Feature::Production), 404);
        $this->validate([
            'move_product_id' => ['required', 'integer', 'exists:pos_products,id'],
            'move_qty' => ['required', 'numeric', 'min:0.001'],
            'move_production_id' => ['nullable', 'integer', 'exists:pos_productions,id'],
        ]);

        $product = PosProduct::query()->find($this->move_product_id);
        if ($product === null) {
            return;
        }

        $qty = (float) $this->move_qty;
        if ($qty > (float) $product->store_stock) {
            $this->addError('move_qty', __('Only :n in the store.', ['n' => rtrim(rtrim(number_format((float) $product->store_stock, 3), '0'), '.')]));

            return;
        }

        // A tagged production must belong to the product being moved.
        $productionId = $this->move_production_id;
        if ($productionId !== null
            && ! PosProduction::query()->whereKey($productionId)->where('pos_product_id', $product->id)->exists()) {
            $productionId = null;
        }

        $transfer = PosStockTransfer::query()->create([
            'pos_product_id' => $product->id,
            'pos_production_id' => $productionId,
            'pos_session_id' => app(PosSessionManager::class)->getActiveSession()?->id,
            'quantity' => $qty,
            'direction' => PosStockTransfer::STORE_TO_SHOP,
            'moved_by_user_id' => Auth::id(),
        ]);
        $transfer->applyMove();

        $this->reset('move_product_id', 'move_qty', 'move_production_id');
        session()->flash('toast', __('Moved :n to the shop.', ['n' => rtrim(rtrim(number_format($qty, 3), '0'), '.')]));
    }

    /**
     * Move finished bottles from the SHOP back to the STORE — the reverse of
     * {@see moveToShop()}. Used to pull stock back off the register, e.g. to
     * return a run's bottles to the store so it can be reopened/reversed. Draws
     * down the shop's `stock_on_hand` and raises `store_stock`; logged as a
     * shop→store transfer so the history shows it.
     */
    public function moveToStore(): void
    {
        abort_unless(Features::enabled(Feature::Production), 404);
        $this->validate([
            'back_product_id' => ['required', 'integer', 'exists:pos_products,id'],
            'back_qty' => ['required', 'numeric', 'min:0.001'],
        ]);

        $product = PosProduct::query()->find($this->back_product_id);
        if ($product === null) {
            return;
        }

        $qty = (float) $this->back_qty;
        if ($qty > (float) $product->stock_on_hand) {
            $this->addError('back_qty', __('Only :n in the shop.', ['n' => rtrim(rtrim(number_format((float) $product->stock_on_hand, 3), '0'), '.')]));

            return;
        }

        $transfer = PosStockTransfer::query()->create([
            'pos_product_id' => $product->id,
            'pos_session_id' => app(PosSessionManager::class)->getActiveSession()?->id,
            'quantity' => $qty,
            'direction' => PosStockTransfer::SHOP_TO_STORE,
            'moved_by_user_id' => Auth::id(),
        ]);
        $transfer->applyMove();

        $this->reset('back_product_id', 'back_qty');
        session()->flash('toast', __('Moved :n back to the store.', ['n' => rtrim(rtrim(number_format($qty, 3), '0'), '.')]));
    }

    /**
     * Correction: take bottles OUT of the store (e.g. a run entered by mistake)
     * without adding them to the shop and without returning materials. Admin-only,
     * and logged as a transfer so the removal is auditable. To also put the
     * materials back, delete the production run instead.
     */
    public function removeFromStore(): void
    {
        abort_unless(Features::enabled(Feature::Production), 404);
        abort_unless(Auth::user()?->isAdmin() === true, 403);
        $this->validate([
            'remove_product_id' => ['required', 'integer', 'exists:pos_products,id'],
            'remove_qty' => ['required', 'numeric', 'min:0.001'],
        ]);

        $product = PosProduct::query()->find($this->remove_product_id);
        if ($product === null) {
            return;
        }

        $qty = (float) $this->remove_qty;
        if ($qty > (float) $product->store_stock) {
            $this->addError('remove_qty', __('Only :n in the store.', ['n' => rtrim(rtrim(number_format((float) $product->store_stock, 3), '0'), '.')]));

            return;
        }

        $transfer = PosStockTransfer::query()->create([
            'pos_product_id' => $product->id,
            'pos_session_id' => app(PosSessionManager::class)->getActiveSession()?->id,
            'quantity' => $qty,
            'direction' => PosStockTransfer::STORE_REMOVE,
            'moved_by_user_id' => Auth::id(),
        ]);
        $transfer->applyMove();

        $this->reset('remove_product_id', 'remove_qty');
        session()->flash('toast', __('Removed :n from the store.', ['n' => rtrim(rtrim(number_format($qty, 3), '0'), '.')]));
    }

    public function render(): View
    {
        // The runs the "From production" picker offers for the selected product.
        $productionOptions = $this->move_product_id === null
            ? collect()
            : PosProduction::query()
                ->where('pos_product_id', $this->move_product_id)
                ->latest('id')->limit(50)
                ->get(['id', 'reference', 'produced_units', 'created_at']);

        return view('pos::productions', [
            'productions' => PosProduction::query()->with('product')->latest('id')->limit(50)->get(),
            'transfers' => PosStockTransfer::query()->with(['product', 'production'])->latest('id')->limit(20)->get(),
            'productionOptions' => $productionOptions,
            'isAdmin' => Auth::user()?->isAdmin() === true,
            // Products that carry finished stock in the store, for the mover + overview.
            'stocked' => PosProduct::query()
                ->where('active', true)
                ->where(function ($q): void {
                    $q->where('store_stock', '>', 0)->orWhereNotNull('bottle_size_ml');
                })
                ->orderBy('name')
                ->get(['id', 'name', 'store_stock', 'stock_on_hand']),
        ]);
    }
}
