<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Business\Feature;
use App\Erp\Business\Features;
use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Pos\Livewire\PosStockReport;
use Modules\Pos\Livewire\ProductionForm;
use Modules\Pos\Livewire\Productions;
use Modules\Pos\Models\PosIngredient;
use Modules\Pos\Models\PosProduct;
use Modules\Pos\Models\PosProduction;
use Modules\Pos\Models\PosStockTransfer;
use Tests\TestCase;

/**
 * Perfume manufacturing: a production run mixes raw materials (ML) into finished
 * bottles that land in the STORE, then a transfer moves them to the SHOP for
 * sale. Gated to the perfumes POS via the Production feature.
 */
final class PosProductionTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('pos');
    }

    private function enableProduction(): void
    {
        Features::setOverrides([Feature::Production->value => true]);
    }

    public function test_a_production_deducts_materials_and_fills_the_store(): void
    {
        $this->enableProduction();
        $oil = PosIngredient::query()->create(['name' => 'Oil', 'unit' => 'ml', 'stock_on_hand' => 1000, 'cost_price' => 2]);
        $ethanol = PosIngredient::query()->create(['name' => 'Ethanol', 'unit' => 'ml', 'stock_on_hand' => 2000, 'cost_price' => 0.05]);
        $perfume = PosProduct::query()->create(['name' => 'Perfume X', 'price' => 5, 'bottle_size_ml' => 50, 'stock_on_hand' => 0, 'store_stock' => 0]);

        Livewire::test(ProductionForm::class)
            ->set('product_id', $perfume->id)
            ->set('lines.0.ingredient_id', $oil->id)
            ->set('lines.0.ml_used', 500)
            ->call('addLine')
            ->set('lines.1.ingredient_id', $ethanol->id)
            ->set('lines.1.ml_used', 1300)
            ->set('produced_units', 36)
            ->call('save')
            ->assertHasNoErrors();

        // Materials deducted.
        $this->assertEqualsWithDelta(500.0, $oil->fresh()->stock_on_hand, 0.001);   // 1000 − 500
        $this->assertEqualsWithDelta(700.0, $ethanol->fresh()->stock_on_hand, 0.001); // 2000 − 1300
        // Bottles land in the STORE, not the shop.
        $this->assertEqualsWithDelta(36.0, $perfume->fresh()->store_stock, 0.001);
        $this->assertEqualsWithDelta(0.0, $perfume->fresh()->stock_on_hand, 0.001);

        $run = PosProduction::query()->latest('id')->first();
        $this->assertNotNull($run);
        $this->assertSame(36, $run->expected_units);           // floor(1800 / 50)
        $this->assertEqualsWithDelta(1800.0, $run->total_mix_ml, 0.001);
        $this->assertEqualsWithDelta(1065.0, $run->total_cost, 0.001); // 500×2 + 1300×0.05
        $this->assertNotNull($run->reference);
    }

    public function test_a_saved_formula_auto_fills_the_next_production(): void
    {
        $this->enableProduction();
        $oil = PosIngredient::query()->create(['name' => 'Oil', 'unit' => 'ml', 'stock_on_hand' => 1000, 'cost_price' => 2]);
        $ethanol = PosIngredient::query()->create(['name' => 'Ethanol', 'unit' => 'ml', 'stock_on_hand' => 2000, 'cost_price' => 0.05]);
        $perfume = PosProduct::query()->create(['name' => 'Perfume F', 'price' => 5, 'bottle_size_ml' => 50]);

        // Save the mix as this perfume's formula.
        Livewire::test(ProductionForm::class)
            ->set('product_id', $perfume->id)
            ->set('lines.0.ingredient_id', $oil->id)
            ->set('lines.0.ml_used', 500)
            ->call('addLine')
            ->set('lines.1.ingredient_id', $ethanol->id)
            ->set('lines.1.ml_used', 1300)
            ->call('saveAsFormula')
            ->assertSet('formulaJustSaved', true);

        $this->assertSame(2, $perfume->formulaLines()->count());

        // A fresh production auto-fills the materials when the perfume is picked.
        Livewire::test(ProductionForm::class)
            ->set('product_id', $perfume->id)
            ->assertCount('lines', 2)
            ->assertSet('lines.0.ingredient_id', (string) $oil->id)
            ->assertSet('lines.0.ml_used', '500')
            ->assertSet('lines.1.ml_used', '1300');
    }

    public function test_a_shortfall_is_recorded_as_variance(): void
    {
        $this->enableProduction();
        $oil = PosIngredient::query()->create(['name' => 'Oil', 'unit' => 'ml', 'stock_on_hand' => 1000, 'cost_price' => 1]);
        $perfume = PosProduct::query()->create(['name' => 'P', 'price' => 5, 'bottle_size_ml' => 50, 'store_stock' => 0]);

        Livewire::test(ProductionForm::class)
            ->set('product_id', $perfume->id)
            ->set('lines.0.ingredient_id', $oil->id)
            ->set('lines.0.ml_used', 1800)
            ->set('produced_units', 30) // 6 short of the expected 36
            ->call('save')
            ->assertHasNoErrors();

        $run = PosProduction::query()->latest('id')->first();
        $this->assertSame(36, $run->expected_units);
        $this->assertSame(30, $run->produced_units);
        $this->assertSame(6, $run->variance());
        $this->assertEqualsWithDelta(30.0, $perfume->fresh()->store_stock, 0.001);
    }

    public function test_moving_stock_from_store_to_shop(): void
    {
        $this->enableProduction();
        $perfume = PosProduct::query()->create(['name' => 'P', 'price' => 5, 'store_stock' => 36, 'stock_on_hand' => 0]);

        Livewire::test(Productions::class)
            ->set('move_product_id', $perfume->id)
            ->set('move_qty', 10)
            ->call('moveToShop')
            ->assertHasNoErrors();

        $perfume->refresh();
        $this->assertEqualsWithDelta(26.0, $perfume->store_stock, 0.001);
        $this->assertEqualsWithDelta(10.0, $perfume->stock_on_hand, 0.001);
        $this->assertSame(1, PosStockTransfer::query()->count());
    }

    public function test_cannot_move_more_than_the_store_holds(): void
    {
        $this->enableProduction();
        $perfume = PosProduct::query()->create(['name' => 'P', 'price' => 5, 'store_stock' => 5, 'stock_on_hand' => 0]);

        Livewire::test(Productions::class)
            ->set('move_product_id', $perfume->id)
            ->set('move_qty', 20)
            ->call('moveToShop')
            ->assertHasErrors('move_qty');

        $this->assertEqualsWithDelta(5.0, $perfume->fresh()->store_stock, 0.001);
    }

    public function test_stock_report_splits_products_from_production_materials(): void
    {
        $this->enableProduction();
        PosIngredient::query()->create(['name' => 'OilMaterialZ', 'unit' => 'ml', 'stock_on_hand' => 1000, 'cost_price' => 1]);
        PosProduct::query()->create(['name' => 'PerfumeSkuZ', 'price' => 5, 'stock_on_hand' => 10]);

        Livewire::test(PosStockReport::class)
            ->set('scope', 'products')
            ->assertSee('PerfumeSkuZ')
            ->assertDontSee('OilMaterialZ')
            ->set('scope', 'materials')
            ->assertSee('OilMaterialZ')
            ->assertDontSee('PerfumeSkuZ');
    }

    public function test_the_production_screens_are_gated_to_the_feature(): void
    {
        // Off by default → the route 404s (the component aborts in mount()).
        $this->get('/app/pos/production')->assertNotFound();

        // On → the component mounts and renders.
        $this->enableProduction();
        Livewire::test(Productions::class)->assertSee('Production & store');
    }
}
