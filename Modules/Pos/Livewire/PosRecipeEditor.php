<?php

declare(strict_types=1);

namespace Modules\Pos\Livewire;

use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Modules\Pos\Livewire\Concerns\CreatesProductInline;
use Modules\Pos\Models\PosCategory;
use Modules\Pos\Models\PosProduct;
use Modules\Pos\Models\PosProductRecipe;

/**
 * Static recipe editor for a finished product: add/remove component
 * (raw material) lines with a fixed quantity consumed per unit sold.
 * Strictly static — no per-sale overrides.
 *
 * The component picker is a searchable combobox with an inline "New product"
 * create (shared {@see CreatesProductInline} trait + `pos::partials.new-product-modal`).
 */
final class PosRecipeEditor extends Component
{
    use CreatesProductInline;

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

    /**
     * Open the inline "New product" modal, pre-filling the typed search text
     * as the name. Creating a product needs the pos.product Create permission.
     */
    public function openProductModal(?string $name = null): void
    {
        $this->guard(Permission::Create);

        $this->newProduct = $this->blankProduct();
        $this->newProduct['name'] = trim((string) $name);
        $this->resetValidation();
        $this->addingProduct = true;
    }

    /**
     * Persist the inline product (shared trait) and select it as the component
     * to add. The combobox appends it to its client-side list via the
     * dispatched event so it's immediately usable.
     */
    public function saveProduct(): void
    {
        $this->guard(Permission::Create);

        $this->newProduct['name'] = trim((string) ($this->newProduct['name'] ?? ''));
        $this->validate($this->inlineProductRules());

        $product = $this->persistInlineProduct();

        $this->componentId = (int) $product->getKey();
        $this->dispatch('product-created', id: (int) $product->getKey(), name: (string) $product->name);

        $this->closeProductModal();
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
            // Inline "New product" modal data (shared partial).
            'categories' => PosCategory::query()->orderBy('name')->get(['id', 'name']),
            'unitOptions' => PosProduct::UNIT_OPTIONS,
            'canCreateProduct' => app(AccessControl::class)->allows(Auth::user(), 'pos.product', Permission::Create),
        ]);
    }
}
