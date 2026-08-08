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
use Modules\Pos\Models\PosIngredient;
use Modules\Pos\Models\PosProduct;
use Modules\Pos\Models\PosProductRecipe;

/**
 * Static recipe editor for a finished product: add/remove component lines with
 * a fixed quantity consumed per unit sold. A component is a product, a
 * condiment OR an ingredient (raw material) — all stock-tracked and decremented
 * on sale. Strictly static — no per-sale overrides.
 *
 * The component picker is a searchable combobox over products + condiments +
 * ingredients, with an inline "New product" create (shared
 * {@see CreatesProductInline} trait + `pos::partials.new-product-modal`). The
 * selected value is a composite key `p:{id}` (product), `c:{id}` (condiment)
 * or `i:{id}` (ingredient).
 */
final class PosRecipeEditor extends Component
{
    use CreatesProductInline;

    public int $productId;

    /** Composite key: "p:{id}" (product), "c:{id}" (condiment) or "i:{id}" (ingredient). */
    public ?string $componentKey = null;

    public string $quantity = '1';

    public function mount(int $productId): void
    {
        $this->guard(Permission::Read);
        $this->productId = $productId;

        // Self-heal an existing offer: refresh its saved cost price from the
        // recipe at TODAY's component costs, so a recipe whose ingredients' costs
        // moved since it was built reflects the new total the moment it's opened
        // (the live table below already shows current costs). Quiet + only when
        // it actually drifted, so opening a product isn't a needless write.
        $product = PosProduct::query()->find($productId);
        if ($product !== null && $product->hasRecipe()) {
            $current = $product->recipeCost();
            if (abs((float) $product->cost_price - $current) > 0.00005) {
                $product->cost_price = $current;
                $product->saveQuietly();
            }
        }
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
                ['parent_product_id' => $this->productId, 'component_condiment_id' => $id, 'component_product_id' => null, 'component_ingredient_id' => null],
                ['quantity_consumed' => $qty],
            );
        } elseif ($type === 'i') {
            PosProductRecipe::query()->updateOrCreate(
                ['parent_product_id' => $this->productId, 'component_ingredient_id' => $id, 'component_product_id' => null, 'component_condiment_id' => null],
                ['quantity_consumed' => $qty],
            );
        } else {
            return;
        }

        $this->componentKey = null;
        $this->quantity = '1';

        $this->syncParentCost();
    }

    /**
     * Roll the recipe up into the parent product's cost price: a product
     * assembled from a bill of materials (a gift box of perfumes + packaging,
     * a sandwich of ingredients…) costs exactly what its components cost. Runs
     * after every recipe change so the cost stays in step. The product form is
     * a separate Livewire island, so the top "Cost price" field reflects this
     * on the next page load; the recipe panel shows it live.
     */
    private function syncParentCost(): void
    {
        $product = PosProduct::query()->find($this->productId);

        if ($product === null) {
            return;
        }

        $product->cost_price = $product->recipeCost();
        $product->save();
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

        $this->syncParentCost();
    }

    /**
     * Edit how much of a component one unit of the product consumes, in the
     * component's own unit (e.g. 0.25 litre of milk per cup). Fractional;
     * ignored if non-positive.
     */
    public function updateLineQuantity(int $recipeId, string $qty): void
    {
        $this->guard(Permission::Write);

        $value = round((float) $qty, 3);

        if ($value <= 0) {
            return;
        }

        PosProductRecipe::query()
            ->where('id', $recipeId)
            ->where('parent_product_id', $this->productId)
            ->update(['quantity_consumed' => $value]);

        $this->syncParentCost();
    }

    public function render(): View
    {
        $lines = PosProductRecipe::query()
            ->with(['component', 'condiment', 'ingredient'])
            ->where('parent_product_id', $this->productId)
            ->get();

        $usedProductIds = $lines->whereNotNull('component_product_id')
            ->pluck('component_product_id')->push($this->productId)->all();
        $usedCondimentIds = $lines->whereNotNull('component_condiment_id')
            ->pluck('component_condiment_id')->all();
        $usedIngredientIds = $lines->whereNotNull('component_ingredient_id')
            ->pluck('component_ingredient_id')->all();

        // The picker offers products AND condiments AND ingredients (all
        // stock-tracked). Each option carries a composite key so addLine knows
        // which it is.
        // Each option carries the component's unit suffix ('' for a plain
        // count / a condiment) so the "Qty / unit" input can show it.
        $unit = static fn (?string $u): string => ($u !== null && $u !== '' && $u !== 'qty') ? $u : '';

        $productOptions = PosProduct::query()
            ->whereNotIn('id', $usedProductIds)
            ->orderBy('name')
            ->get(['id', 'name', 'unit'])
            ->map(fn (PosProduct $p): array => ['key' => 'p:' . $p->id, 'name' => (string) $p->name, 'type' => 'product', 'unit' => $unit($p->unit)]);

        $condimentOptions = PosCondiment::query()
            ->whereNotIn('id', $usedCondimentIds)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (PosCondiment $c): array => ['key' => 'c:' . $c->id, 'name' => (string) $c->name, 'type' => 'condiment', 'unit' => '']);

        $ingredientOptions = PosIngredient::query()
            ->whereNotIn('id', $usedIngredientIds)
            ->orderBy('name')
            ->get(['id', 'name', 'unit'])
            ->map(fn (PosIngredient $i): array => ['key' => 'i:' . $i->id, 'name' => (string) $i->name, 'type' => 'ingredient', 'unit' => $unit($i->unit)]);

        $componentOptions = $productOptions->concat($condimentOptions)->concat($ingredientOptions)->values();

        // Unit of the currently-picked component, so the "Qty / unit" input can
        // hint the unit you're entering (e.g. enter 0.25 for litres).
        $selected = $this->componentKey !== null ? $componentOptions->firstWhere('key', $this->componentKey) : null;
        $selectedUnit = is_array($selected) ? (string) ($selected['unit'] ?? '') : '';

        return view('pos::recipe-editor', [
            'lines' => $lines,
            'recipeCost' => round($lines->sum(fn (PosProductRecipe $l): float => $l->lineCost()), 4),
            'componentOptions' => $componentOptions,
            'selectedUnit' => $selectedUnit,
            'product' => PosProduct::query()->find($this->productId),
            // Inline "New product" modal data (shared partial).
            'categories' => PosCategory::query()->orderBy('name')->get(['id', 'name']),
            'unitOptions' => PosProduct::UNIT_OPTIONS,
            'canCreateProduct' => app(AccessControl::class)->allows(Auth::user(), 'pos.product', Permission::Create),
        ]);
    }
}
