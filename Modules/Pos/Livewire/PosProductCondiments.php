<?php

declare(strict_types=1);

namespace Modules\Pos\Livewire;

use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Modules\Pos\Models\PosCondiment;
use Modules\Pos\Models\PosProduct;

/**
 * Per-product condiment editor, embedded on the product page beneath the
 * engine form + recipe editor. A checklist of every active condiment; ticking
 * one assigns it to this product (a row in the `pos_condiment_product` pivot)
 * so the register offers it as an add-on whenever this product is on a line —
 * on top of any category-scoped / global condiments.
 *
 * Admin-only in practice: `pos.product` Write is required to toggle, and
 * cashiers have no `pos.product` access at all so they never reach this page.
 */
final class PosProductCondiments extends Component
{
    public int $productId;

    public function mount(int $productId): void
    {
        $this->guard(Permission::Read);
        $this->productId = $productId;
    }

    private function guard(Permission $permission): void
    {
        app(AccessControl::class)->authorize(Auth::user(), 'pos.product', $permission);
    }

    /**
     * Attach / detach a condiment to this product (idempotent toggle). Guarded
     * by Write and validated against a real condiment so a crafted id can't
     * write a dangling pivot row.
     */
    public function toggle(int $condimentId): void
    {
        $this->guard(Permission::Write);

        $product = PosProduct::query()->find($this->productId);

        if ($product === null || ! PosCondiment::query()->whereKey($condimentId)->exists()) {
            return;
        }

        $product->condiments()->toggle($condimentId);
    }

    public function render(): View
    {
        $product = PosProduct::query()->with('condiments:id')->findOrFail($this->productId);

        return view('pos::product-condiments', [
            'condiments' => PosCondiment::query()
                ->where('active', true)
                ->orderBy('sequence')->orderBy('name')
                ->get(),
            'assignedIds' => $product->condiments->pluck('id')->all(),
            'canManage' => app(AccessControl::class)->allows(Auth::user(), 'pos.product', Permission::Write),
        ]);
    }
}
