<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Pos\Enums\OrderState;
use Modules\Pos\Enums\SessionState;
use Modules\Pos\Livewire\PosRecipeEditor;
use Modules\Pos\Models\PosCondiment;
use Modules\Pos\Models\PosIngredient;
use Modules\Pos\Models\PosOrder;
use Modules\Pos\Models\PosPaymentMethod;
use Modules\Pos\Models\PosProduct;
use Modules\Pos\Models\PosProductRecipe;
use Modules\Pos\Models\PosSession;
use Tests\TestCase;

/**
 * The recipe editor's component picker: a searchable combobox over products
 * AND condiments (both stock-tracked), with an inline "New product" create.
 */
final class PosRecipeEditorTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('pos');
    }

    private function parent(): PosProduct
    {
        return PosProduct::query()->create(['name' => 'Burger', 'price' => 2, 'tax_rate' => 0, 'active' => true]);
    }

    public function test_line_quantity_is_editable_inline_and_unit_aware(): void
    {
        $cup = $this->parent();
        // Milk is stocked in litres; 4 L on hand.
        $milk = PosIngredient::query()->create(['name' => 'Milk', 'cost_price' => 0, 'stock_on_hand' => 4, 'unit' => 'l']);
        $recipe = PosProductRecipe::query()->create([
            'parent_product_id' => $cup->id,
            'component_ingredient_id' => $milk->id,
            'quantity_consumed' => 1,
        ]);

        // The line carries the component's unit, and 4 / 1 = 4 servings.
        $this->assertSame('l', $recipe->componentUnit());
        $this->assertSame(4, $cup->fresh()?->theoreticalYield());

        // Edit the per-cup consumption to 0.25 L inline.
        Livewire::test(PosRecipeEditor::class, ['productId' => $cup->id])
            ->call('updateLineQuantity', $recipe->id, '0.25')
            ->assertHasNoErrors();

        $this->assertSame(0.25, (float) $recipe->fresh()?->quantity_consumed);
        // 4 L / 0.25 L = 16 servings.
        $this->assertSame(16, $cup->fresh()?->theoreticalYield());
    }

    public function test_inline_create_makes_a_product_and_selects_it_as_the_component(): void
    {
        $parent = $this->parent();

        Livewire::test(PosRecipeEditor::class, ['productId' => $parent->id])
            ->call('openProductModal', 'Cheddar')
            ->assertSet('addingProduct', true)
            ->set('newProduct.cost_price', '0.40')
            ->set('newProduct.stock_on_hand', '50')
            ->call('saveProduct')
            ->assertHasNoErrors()
            ->assertSet('addingProduct', false)
            ->assertSet('componentKey', 'p:' . (int) PosProduct::query()->where('name->en', 'Cheddar')->value('id'));

        $product = PosProduct::query()->where('name->en', 'Cheddar')->firstOrFail();
        $this->assertSame(50.0, (float) $product->stock_on_hand);
    }

    public function test_inline_create_requires_a_name(): void
    {
        $parent = $this->parent();

        Livewire::test(PosRecipeEditor::class, ['productId' => $parent->id])
            ->call('openProductModal', '')
            ->set('newProduct.name', '')
            ->call('saveProduct')
            ->assertHasErrors(['newProduct.name' => 'required']);

        $this->assertSame(1, PosProduct::query()->count());
    }

    public function test_created_product_component_can_be_added(): void
    {
        $parent = $this->parent();

        Livewire::test(PosRecipeEditor::class, ['productId' => $parent->id])
            ->call('openProductModal', 'Bun')
            ->call('saveProduct')
            ->set('quantity', '2')
            ->call('addLine')
            ->assertHasNoErrors();

        $component = PosProduct::query()->where('name->en', 'Bun')->firstOrFail();
        $this->assertDatabaseHas('product_recipes', [
            'parent_product_id' => $parent->id,
            'component_product_id' => $component->id,
            'component_condiment_id' => null,
            'quantity_consumed' => 2,
        ]);
    }

    public function test_a_condiment_can_be_added_as_a_component(): void
    {
        $parent = $this->parent();
        $mayo = PosCondiment::query()->create(['name' => 'Mayo', 'price' => 0, 'stock_on_hand' => 100, 'active' => true]);

        Livewire::test(PosRecipeEditor::class, ['productId' => $parent->id])
            ->set('componentKey', 'c:' . $mayo->id)
            ->set('quantity', '3')
            ->call('addLine')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('product_recipes', [
            'parent_product_id' => $parent->id,
            'component_product_id' => null,
            'component_condiment_id' => $mayo->id,
            'quantity_consumed' => 3,
        ]);
    }

    public function test_selling_decrements_condiment_stock(): void
    {
        $parent = $this->parent();
        $mayo = PosCondiment::query()->create(['name' => 'Mayo', 'price' => 0, 'stock_on_hand' => 100, 'active' => true]);
        PosProductRecipe::query()->create([
            'parent_product_id' => $parent->id,
            'component_condiment_id' => $mayo->id,
            'quantity_consumed' => 2,
        ]);
        $cash = PosPaymentMethod::query()->create(['name' => 'Cash', 'is_cash' => true, 'sequence' => 1, 'active' => true]);

        $session = PosSession::query()->create(['reference' => 'POS-S/0001', 'state' => SessionState::Opened, 'opening_cash' => 0, 'opened_at' => now()]);
        $order = PosOrder::query()->create(['pos_session_id' => $session->id, 'reference' => 'POS/1/0001', 'state' => OrderState::Draft]);
        $line = $order->lines()->make(['pos_product_id' => $parent->id, 'name' => 'Burger', 'qty' => 3, 'unit_price' => 2, 'discount' => 0, 'tax_rate' => 0]);
        $line->recompute();
        $line->save();
        $order->recalculate();
        $order->registerPayment($cash, (float) $order->total);
        $order->finalizeSale();

        // 3 burgers × 2 mayo = 6 consumed → 94 left.
        $this->assertSame(94.0, (float) $mayo->fresh()?->stock_on_hand);
    }

    public function test_theoretical_yield_accounts_for_condiment_stock(): void
    {
        $parent = $this->parent();
        // 5 mayo in stock, 2 per burger → 2 servings possible.
        $mayo = PosCondiment::query()->create(['name' => 'Mayo', 'price' => 0, 'stock_on_hand' => 5, 'active' => true]);
        PosProductRecipe::query()->create([
            'parent_product_id' => $parent->id,
            'component_condiment_id' => $mayo->id,
            'quantity_consumed' => 2,
        ]);

        $this->assertSame(2, $parent->fresh()?->theoreticalYield());
    }
}
