<?php

declare(strict_types=1);

namespace Modules\Pos\Livewire;

use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Modules\Pos\Models\PosProduct;
use Modules\Pos\Models\PosProductRecipe;

/**
 * Static recipe editor for a finished product: add/remove component
 * (raw material) lines with a fixed quantity consumed per unit sold.
 * Strictly static — no per-sale overrides.
 */
final class PosRecipeEditor extends Component
{
    public int $productId;

    public ?int $componentId = null;

    public string $quantity = '1';

    public function mount(int $productId): void
    {
        $this->guard(Permission::Read);
        $this->productId = $productId;
    }

    private function guard(Permission $permission): void
    {
        app(AccessControl::class)->authorize(Auth::user(), 'pos.product', $permission);
    }

    public function addLine(): void
    {
        $this->guard(Permission::Write);

        $qty = round((float) $this->quantity, 3);

        if ($this->componentId === null
            || $this->componentId === $this->productId
            || $qty <= 0) {
            return;
        }

        PosProductRecipe::query()->updateOrCreate(
            [
                'parent_product_id' => $this->productId,
                'component_product_id' => $this->componentId,
            ],
            ['quantity_consumed' => $qty],
        );

        $this->componentId = null;
        $this->quantity = '1';
    }

    public function removeLine(int $recipeId): void
    {
        $this->guard(Permission::Write);

        PosProductRecipe::query()
            ->where('id', $recipeId)
            ->where('parent_product_id', $this->productId)
            ->delete();
    }

    public function render(): View
    {
        $lines = PosProductRecipe::query()
            ->with('component')
            ->where('parent_product_id', $this->productId)
            ->get();

        $usedIds = $lines->pluck('component_product_id')->push($this->productId)->all();

        $components = PosProduct::query()
            ->whereNotIn('id', $usedIds)
            ->orderBy('name')
            ->get();

        return view('pos::recipe-editor', [
            'lines' => $lines,
            'components' => $components,
            'product' => PosProduct::query()->find($this->productId),
        ]);
    }
}
