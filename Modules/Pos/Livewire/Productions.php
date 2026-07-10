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

    public function mount(): void
    {
        abort_unless(Features::enabled(Feature::Production), 404);
    }

    public function moveToShop(): void
    {
        abort_unless(Features::enabled(Feature::Production), 404);
        $this->validate([
            'move_product_id' => ['required', 'integer', 'exists:pos_products,id'],
            'move_qty' => ['required', 'numeric', 'min:0.001'],
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

        $transfer = PosStockTransfer::query()->create([
            'pos_product_id' => $product->id,
            'pos_session_id' => app(PosSessionManager::class)->getActiveSession()?->id,
            'quantity' => $qty,
            'direction' => PosStockTransfer::STORE_TO_SHOP,
            'moved_by_user_id' => Auth::id(),
        ]);
        $transfer->applyMove();

        $this->reset('move_product_id', 'move_qty');
        session()->flash('toast', __('Moved :n to the shop.', ['n' => rtrim(rtrim(number_format($qty, 3), '0'), '.')]));
    }

    public function render(): View
    {
        return view('pos::productions', [
            'productions' => PosProduction::query()->with('product')->latest('id')->limit(50)->get(),
            'transfers' => PosStockTransfer::query()->with('product')->latest('id')->limit(20)->get(),
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
