<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Pos\Livewire\PosRecipeEditor;
use Modules\Pos\Models\PosProduct;
use Modules\Pos\Models\PosProductRecipe;
use Tests\TestCase;

/**
 * The recipe editor's component picker: a searchable combobox with an inline
 * "New product" create (shared with the Purchases bill editor).
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

    public function test_inline_create_makes_a_product_and_selects_it_as_the_component(): void
    {
        $parent = $this->parent();

        Livewire::test(PosRecipeEditor::class, ['productId' => $parent->id])
            ->call('openProductModal', 'Cheddar')
            ->assertSet('addingProduct', true)
            ->assertSet('newProduct.name', 'Cheddar')
            ->set('newProduct.cost_price', '0.40')
            ->set('newProduct.stock_on_hand', '50')
            ->call('saveProduct')
            ->assertHasNoErrors()
            ->assertSet('addingProduct', false)
            ->assertSet('componentId', (int) PosProduct::query()->where('name->en', 'Cheddar')->value('id'));

        $product = PosProduct::query()->where('name->en', 'Cheddar')->firstOrFail();
        $this->assertTrue((bool) $product->active);
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

        // Only the parent exists — no component product was created.
        $this->assertSame(1, PosProduct::query()->count());
    }

    public function test_created_component_can_be_added_to_the_recipe(): void
    {
        $parent = $this->parent();

        Livewire::test(PosRecipeEditor::class, ['productId' => $parent->id])
            ->call('openProductModal', 'Mayo')
            ->call('saveProduct')
            ->set('quantity', '2')
            ->call('addLine')
            ->assertHasNoErrors();

        $component = PosProduct::query()->where('name->en', 'Mayo')->firstOrFail();
        $this->assertDatabaseHas('product_recipes', [
            'parent_product_id' => $parent->id,
            'component_product_id' => $component->id,
            'quantity_consumed' => 2,
        ]);
    }
}
