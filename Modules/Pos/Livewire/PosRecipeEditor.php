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
use Modules\Pos\Models\PosCondiment;
use Modules\Pos\Models\PosProduct;
use Modules\Pos\Models\PosProductRecipe;

/**
 * Static recipe editor for a finished product: add/remove component lines with
 * a fixed quantity consumed per unit sold. A component is EITHER a product
 * (raw material) OR a condiment — both are stock-tracked and decremented on
 * sale. Strictly static — no per-sale overrides.
 *
 * The component picker is a searchable combobox over products + condiments,
 * with an inline "New product" create (shared {@see CreatesProductInline}
 * trait + `pos::partials.new-product-modal`). The selected value is a composite
 * key `p:{id}` (product) or `c:{id}` (condiment).
 */
final class PosRecipeEditor extends Component
{
    use CreatesProductInline;

    public int $productId;

    /** Composite component key: "p:{id}" (product) or "c:{id}" (condiment). */
    public ?string $componentKey = null;

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

        if ($this->componentKey === null || $qty <= 0) {
            return;
        }

        [$type, $idStr] = array_pad(explode(':', $this->componentKey, 2), 2, null);
        $id = (int) $idStr;

        if ($id <= 0) {
            return;
        }

        if ($type === 'p') {
            // A product can't be a component of itself.
            if ($id === $this->productId) {
                return;
            }

            PosProductRecipe::query()->updateOrCreate(
                ['parent_product_id' => $this->productId, 'component_product_id' => $id, 'component_condiment_id' => null],
                ['quantity_consumed' => $qty],
            );
        } elseif ($type === 'c') {
            PosProductRecipe::query()->updateOrCreate(
                ['parent_product_id' => $this->productId, 'component_condiment_id' => $id, 'component_product_id' => null],
                ['quantity_consumed' => $qty],
            );
        } else {
            return;
        }

        $this->componentKey = null;
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

        $this->componentKey = 'p:' . $product->getKey();
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
            ->with(['component', 'condiment'])
            ->where('parent_product_id', $this->productId)
            ->get();

        $usedProductIds = $lines->whereNotNull('component_product_id')
            ->pluck('component_product_id')->push($this->productId)->all();
        $usedCondimentIds = $lines->whereNotNull('component_condiment_id')
            ->pluck('component_condiment_id')->all();

        // The picker offers products AND condiments (both stock-tracked). Each
        // option carries a composite key so addLine knows which it is.
        $productOptions = PosProduct::query()
            ->whereNotIn('id', $usedProductIds)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (PosProduct $p): array => ['key' => 'p:' . $p->id, 'name' => (string) $p->name, 'type' => 'product']);

        $condimentOptions = PosCondiment::query()
            ->whereNotIn('id', $usedCondimentIds)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (PosCondiment $c): array => ['key' => 'c:' . $c->id, 'name' => (string) $c->name, 'type' => 'condiment']);

        $componentOptions = $productOptions->concat($condimentOptions)->values();

        return view('pos::recipe-editor', [
            'lines' => $lines,
            'componentOptions' => $componentOptions,
            'product' => PosProduct::query()->find($this->productId),
            // Inline "New product" modal data (shared partial).
            'categories' => PosCategory::query()->orderBy('name')->get(['id', 'name']),
            'unitOptions' => PosProduct::UNIT_OPTIONS,
            'canCreateProduct' => app(AccessControl::class)->allows(Auth::user(), 'pos.product', Permission::Create),
        ]);
    }
}
