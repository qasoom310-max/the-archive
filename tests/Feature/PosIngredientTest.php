<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Livewire\Views\FormView;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Pos\Enums\OrderState;
use Modules\Pos\Enums\SessionState;
use Modules\Pos\Livewire\PosIngredientCategories;
use Modules\Pos\Livewire\PosIngredientCategoryForm;
use Modules\Pos\Livewire\PosIngredientForm;
use Modules\Pos\Livewire\PosIngredients;
use Modules\Pos\Livewire\PosRecipeEditor;
use Modules\Pos\Models\PosIngredient;
use Modules\Pos\Models\PosIngredientCategory;
use Modules\Pos\Models\PosOrder;
use Modules\Pos\Models\PosPaymentMethod;
use Modules\Pos\Models\PosProduct;
use Modules\Pos\Models\PosProductRecipe;
use Modules\Pos\Models\PosSession;
use Modules\Pos\Services\PosStockReportData;
use Modules\Purchases\Models\Purchase;
use Modules\Purchases\Services\PurchaseConfirmer;
use Tests\TestCase;

/**
 * POS Ingredients: raw materials (flour, oil, beans…) that are stock-tracked
 * recipe components and purchasable line items, but never sold at the register.
 * Mirrors the condiment integration across the recipe editor, sale-time
 * consumption, theoretical yield, the stock report and the purchases module.
 */
final class PosIngredientTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        // Installing purchases pulls in contacts + pos + inventory + accounting,
        // so both the POS ingredient catalogue and the purchase_lines ingredient
        // column exist.
        app(ModuleManager::class)->install('purchases');
    }

    private function parent(): PosProduct
    {
        return PosProduct::query()->create(['name' => 'Loaf', 'price' => 3, 'tax_rate' => 0, 'active' => true]);
    }

    public function test_an_ingredient_can_be_added_as_a_recipe_component(): void
    {
        $parent = $this->parent();
        $flour = PosIngredient::query()->create(['name' => 'Flour', 'cost_price' => 0.8, 'stock_on_hand' => 50, 'unit' => 'kg']);

        Livewire::test(PosRecipeEditor::class, ['productId' => $parent->id])
            ->set('componentKey', 'i:' . $flour->id)
            ->set('quantity', '0.25')
            ->call('addLine')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('product_recipes', [
            'parent_product_id' => $parent->id,
            'component_product_id' => null,
            'component_condiment_id' => null,
            'component_ingredient_id' => $flour->id,
            'quantity_consumed' => 0.25,
        ]);
    }

    public function test_recipe_editor_offers_ingredients_in_the_picker(): void
    {
        $parent = $this->parent();
        PosIngredient::query()->create(['name' => 'Olive oil', 'cost_price' => 5.5, 'stock_on_hand' => 10, 'unit' => 'l']);

        Livewire::test(PosRecipeEditor::class, ['productId' => $parent->id])
            ->assertViewHas('componentOptions', fn ($options): bool => collect($options)
                ->contains(fn (array $o): bool => $o['type'] === 'ingredient' && $o['name'] === 'Olive oil'));
    }

    public function test_selling_decrements_ingredient_stock(): void
    {
        $parent = $this->parent();
        $flour = PosIngredient::query()->create(['name' => 'Flour', 'cost_price' => 0.8, 'stock_on_hand' => 50]);
        PosProductRecipe::query()->create([
            'parent_product_id' => $parent->id,
            'component_ingredient_id' => $flour->id,
            'quantity_consumed' => 2,
        ]);
        $cash = PosPaymentMethod::query()->create(['name' => 'Cash', 'is_cash' => true, 'sequence' => 1, 'active' => true]);

        $session = PosSession::query()->create(['reference' => 'POS-S/0001', 'state' => SessionState::Opened, 'opening_cash' => 0, 'opened_at' => now()]);
        $order = PosOrder::query()->create(['pos_session_id' => $session->id, 'reference' => 'POS/1/0001', 'state' => OrderState::Draft]);
        $line = $order->lines()->make(['pos_product_id' => $parent->id, 'name' => 'Loaf', 'qty' => 3, 'unit_price' => 3, 'discount' => 0, 'tax_rate' => 0]);
        $line->recompute();
        $line->save();
        $order->recalculate();
        $order->registerPayment($cash, (float) $order->total);
        $order->finalizeSale();

        // 3 loaves × 2 kg = 6 consumed → 44 left.
        $this->assertSame(44.0, (float) $flour->fresh()?->stock_on_hand);
    }

    public function test_theoretical_yield_accounts_for_ingredient_stock(): void
    {
        $parent = $this->parent();
        // 5 units in stock, 2 per loaf → 2 servings possible.
        $flour = PosIngredient::query()->create(['name' => 'Flour', 'cost_price' => 0.8, 'stock_on_hand' => 5]);
        PosProductRecipe::query()->create([
            'parent_product_id' => $parent->id,
            'component_ingredient_id' => $flour->id,
            'quantity_consumed' => 2,
        ]);

        $this->assertSame(2, $parent->fresh()?->theoreticalYield());
    }

    public function test_confirming_a_purchase_raises_ingredient_stock(): void
    {
        $flour = PosIngredient::query()->create(['name' => 'Flour', 'cost_price' => 0.8, 'stock_on_hand' => 10]);

        $purchase = Purchase::query()->create(['date' => '2026-06-24', 'is_stock_purchase' => true]);
        $purchase->lines()->create([
            'pos_ingredient_id' => $flour->id,
            'description' => 'Flour',
            'quantity' => 15,
            'unit_cost' => 0.8,
        ]);

        app(PurchaseConfirmer::class)->confirm($purchase);

        // 10 + 15 = 25 on hand (no warehouse ledger move — ingredients aren't
        // on the Inventory ledger).
        $this->assertSame(25.0, (float) $flour->fresh()?->stock_on_hand);
    }

    public function test_stock_report_includes_ingredients_with_valuation(): void
    {
        PosIngredient::query()->create(['name' => 'Coffee beans', 'cost_price' => 12.0, 'stock_on_hand' => 4, 'unit' => 'kg']);

        $rows = app(PosStockReportData::class)->rows('', '', false);
        $beans = $rows->firstWhere('name', 'Coffee beans');

        $this->assertNotNull($beans);
        $this->assertTrue($beans->isIngredient());
        $this->assertSame('/app/pos/ingredient/' . $beans->id, $beans->url());
        // 4 kg × 12.0 cost = 48.0 valuation (ingredients carry a tracked cost,
        // unlike condiments).
        $this->assertSame(48.0, $beans->value);
    }

    public function test_ingredient_list_and_form_pages_render(): void
    {
        $flour = PosIngredient::query()->create(['name' => 'Flour', 'cost_price' => 0.8, 'stock_on_hand' => 50]);

        Livewire::test(PosIngredients::class)->assertOk();
        Livewire::test(PosIngredientForm::class, ['id' => $flour->id])->assertOk();
    }

    public function test_creating_a_duplicate_ingredient_name_is_blocked(): void
    {
        PosIngredient::query()->create(['name' => 'Qahwa', 'cost_price' => 1, 'stock_on_hand' => 0]);

        // Same name (and case-insensitively / with stray spaces) is refused —
        // the engine surfaces an inline "already in the list" error instead of
        // creating a second row.
        Livewire::test(FormView::class, [
            'model' => PosIngredient::class,
            'modelKey' => 'pos.ingredient',
            'recordId' => null,
            'title' => 'New ingredient',
        ])
            ->set('form.name', '  qahwa ')
            ->call('save')
            ->assertHasErrors(['form.name']);

        $this->assertSame(1, PosIngredient::query()->count());
    }

    public function test_a_distinct_ingredient_name_saves(): void
    {
        PosIngredient::query()->create(['name' => 'Qahwa', 'cost_price' => 1, 'stock_on_hand' => 0]);

        Livewire::test(FormView::class, [
            'model' => PosIngredient::class,
            'modelKey' => 'pos.ingredient',
            'recordId' => null,
            'title' => 'New ingredient',
        ])
            ->set('form.name', 'Sukkar')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(2, PosIngredient::query()->count());
    }

    public function test_an_ingredient_can_be_grouped_under_a_managed_category(): void
    {
        $oils = PosIngredientCategory::query()->create(['name' => 'Oils', 'sequence' => 1]);
        $oil = PosIngredient::query()->create([
            'name' => 'Rose oil', 'cost_price' => 30, 'stock_on_hand' => 5,
            'pos_ingredient_category_id' => $oils->id,
        ]);

        $this->assertSame($oils->id, $oil->category?->id);
        $this->assertSame('Oils', $oil->category_name);
        $this->assertTrue($oils->ingredients->contains($oil));
    }

    public function test_the_ingredient_form_saves_a_category(): void
    {
        $bottles = PosIngredientCategory::query()->create(['name' => 'Bottles', 'sequence' => 2]);

        Livewire::test(FormView::class, [
            'model' => PosIngredient::class,
            'modelKey' => 'pos.ingredient',
            'recordId' => null,
            'title' => 'New ingredient',
        ])
            ->set('form.name', 'Bottle 50ml')
            ->set('form.pos_ingredient_category_id', $bottles->id)
            ->call('save')
            ->assertHasNoErrors();

        $saved = PosIngredient::query()->where('pos_ingredient_category_id', $bottles->id)->first();
        $this->assertNotNull($saved);
        $this->assertSame('Bottle 50ml', $saved->name);
    }

    public function test_the_ingredient_category_list_and_form_pages_render(): void
    {
        $cat = PosIngredientCategory::query()->create(['name' => 'Caps', 'sequence' => 3]);

        // The menu links to /app/pos/ingredient_category — these are the
        // bespoke wrappers that path resolves to. (HTTP gets can't be used
        // here: module routes register at boot, before the per-test install.)
        Livewire::test(PosIngredientCategories::class)->assertOk();
        Livewire::test(PosIngredientCategoryForm::class)->assertOk();               // new
        Livewire::test(PosIngredientCategoryForm::class, ['id' => $cat->id])->assertOk(); // edit

        $this->assertSame('Caps', $cat->fresh()?->name);
    }

    public function test_resaving_an_ingredient_under_its_own_name_is_allowed(): void
    {
        $halib = PosIngredient::query()->create(['name' => 'Halib', 'cost_price' => 1, 'stock_on_hand' => 0]);

        // Editing the record and keeping its own name must NOT trip the
        // uniqueness rule (the record excludes itself).
        Livewire::test(FormView::class, [
            'model' => PosIngredient::class,
            'modelKey' => 'pos.ingredient',
            'recordId' => $halib->id,
            'title' => 'Edit ingredient',
        ])
            ->set('form.cost_price', 2)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(2.0, (float) $halib->fresh()?->cost_price);
    }
}
