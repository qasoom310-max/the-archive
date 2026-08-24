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
use Modules\Pos\Models\PosIngredientCategory;
use Modules\Pos\Models\PosProduct;
use Modules\Pos\Models\PosProduction;
use Modules\Pos\Models\PosProductionLine;
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
        // The finished perfume adopts the per-bottle material cost (1065 ÷ 36).
        $this->assertEqualsWithDelta(29.583, $perfume->fresh()->cost_price, 0.01);
    }

    public function test_the_form_previews_material_and_packaging_costs_in_the_yield(): void
    {
        $this->enableProduction();
        $oil = PosIngredient::query()->create(['name' => 'Oil', 'unit' => 'ml', 'stock_on_hand' => 1000, 'cost_price' => 2]);
        $bottle = PosIngredient::query()->create(['name' => 'Bottle', 'unit' => 'pcs', 'stock_on_hand' => 1000, 'cost_price' => 0.30]);
        $perfume = PosProduct::query()->create(['name' => 'Perfume X', 'price' => 5, 'bottle_size_ml' => 50, 'store_stock' => 0]);

        Livewire::test(ProductionForm::class)
            ->set('product_id', $perfume->id)
            ->set('lines.0.ingredient_id', $oil->id)
            ->set('lines.0.ml_used', 500)      // 500 ml × 2 = 1000
            ->call('addPackaging')
            ->set('packaging.0.ingredient_id', $bottle->id)
            ->set('packaging.0.qty', 1)        // 1 per bottle
            ->set('produced_units', 10)        // × 10 bottles × 0.30 = 3.00
            ->assertViewHas('materialsCost', 1000.0)
            ->assertViewHas('packagingCost', 3.0)
            ->assertViewHas('totalCost', 1003.0)
            ->assertViewHas('unitCost', 100.3); // 1003 ÷ 10
    }

    public function test_production_cost_recomputes_the_last_run_at_current_material_prices(): void
    {
        $this->enableProduction();
        $oil = PosIngredient::query()->create(['name' => 'Oil', 'unit' => 'ml', 'stock_on_hand' => 10000, 'cost_price' => 2]);
        $bottle = PosIngredient::query()->create(['name' => 'Bottle', 'unit' => 'pcs', 'stock_on_hand' => 1000, 'cost_price' => 0.30]);
        $perfume = PosProduct::query()->create(['name' => 'Perfume X', 'price' => 20, 'bottle_size_ml' => 50, 'cost_price' => 0]);

        // Record a run: 500 ml oil (×2) + 1 bottle/bottle (×0.30), 10 bottles.
        Livewire::test(ProductionForm::class)
            ->set('product_id', $perfume->id)
            ->set('lines.0.ingredient_id', $oil->id)
            ->set('lines.0.ml_used', 500)
            ->call('addPackaging')
            ->set('packaging.0.ingredient_id', $bottle->id)
            ->set('packaging.0.qty', 1)
            ->set('produced_units', 10)
            ->call('save')
            ->assertHasNoErrors();

        // (500×2 + 1×10×0.30) ÷ 10 = 100.3 — snapshotted onto the product.
        $this->assertEqualsWithDelta(100.30, $perfume->fresh()->cost_price, 0.001);
        $this->assertEqualsWithDelta(100.30, $perfume->fresh()->productionCost(), 0.001);

        // A material's price rises: the production cost recomputes at the NEW
        // price (150.3), and the stored cost_price now FOLLOWS it — the cascade
        // keeps the snapshot honest so it can't disagree with the live figure.
        $oil->update(['cost_price' => 3]);
        $this->assertEqualsWithDelta(150.30, $perfume->fresh()->productionCost(), 0.001); // (500×3 + 3) ÷ 10
        $this->assertEqualsWithDelta(150.30, $perfume->fresh()->cost_price, 0.001);        // followed the material price
    }

    public function test_a_recipe_prices_a_produced_component_at_its_live_cost_not_a_stale_snapshot(): void
    {
        $this->enableProduction();
        $oil = PosIngredient::query()->create(['name' => 'Oil', 'unit' => 'ml', 'stock_on_hand' => 10000, 'cost_price' => 2]);
        $perfume = PosProduct::query()->create(['name' => 'Perfume', 'price' => 20, 'bottle_size_ml' => 50, 'cost_price' => 0]);

        // Produce the perfume: 500 ml oil, 10 bottles → 100/bottle, snapshotted.
        Livewire::test(ProductionForm::class)
            ->set('product_id', $perfume->id)
            ->set('lines.0.ingredient_id', $oil->id)
            ->set('lines.0.ml_used', 500)
            ->set('produced_units', 10)
            ->call('save')
            ->assertHasNoErrors();
        $this->assertEqualsWithDelta(100.0, $perfume->fresh()->cost_price, 0.01);

        // Oil price doubles → the perfume now really costs 200/bottle to make.
        $oil->update(['cost_price' => 4]);

        // A gift box whose recipe uses one of that perfume.
        $box = PosProduct::query()->create(['name' => 'Gift box', 'price' => 50, 'cost_price' => 0]);
        $box->recipeLines()->create(['component_product_id' => $perfume->id, 'quantity_consumed' => 1]);

        // The recipe reflects the CURRENT production cost (200), not the 100 snapshot.
        $this->assertEqualsWithDelta(200.0, $box->fresh()->recipeCost(), 0.01);
    }

    public function test_opening_the_recipe_editor_refreshes_a_stale_offer_cost(): void
    {
        $this->enableProduction();
        $oil = PosIngredient::query()->create(['name' => 'Oil', 'unit' => 'ml', 'stock_on_hand' => 10000, 'cost_price' => 2]);
        $perfume = PosProduct::query()->create(['name' => 'Perfume', 'price' => 20, 'bottle_size_ml' => 50, 'cost_price' => 0]);

        Livewire::test(ProductionForm::class)
            ->set('product_id', $perfume->id)
            ->set('lines.0.ingredient_id', $oil->id)
            ->set('lines.0.ml_used', 500)
            ->set('produced_units', 10)
            ->call('save');

        $box = PosProduct::query()->create(['name' => 'Gift box', 'price' => 50, 'cost_price' => 100]);
        $box->recipeLines()->create(['component_product_id' => $perfume->id, 'quantity_consumed' => 1]);

        $oil->update(['cost_price' => 4]); // perfume now 200/bottle

        // Just opening the offer's recipe editor snaps its saved cost to 200.
        Livewire::test(\Modules\Pos\Livewire\PosRecipeEditor::class, ['productId' => $box->id]);
        $this->assertEqualsWithDelta(200.0, $box->fresh()->cost_price, 0.01);
    }

    public function test_a_produced_product_shows_its_production_cost_hint(): void
    {
        $this->enableProduction();
        $oil = PosIngredient::query()->create(['name' => 'Oil', 'unit' => 'ml', 'stock_on_hand' => 1000, 'cost_price' => 2]);
        $bottle = PosIngredient::query()->create(['name' => 'Bottle', 'unit' => 'pcs', 'stock_on_hand' => 1000, 'cost_price' => 0.30]);
        $perfume = PosProduct::query()->create(['name' => 'Perfume X', 'price' => 20, 'bottle_size_ml' => 50, 'cost_price' => 0]);

        // Standard formula: 500 ml oil per batch (yields floor(500/50)=10 bottles),
        // 1 bottle of packaging per finished bottle.
        $perfume->formulaLines()->create(['pos_ingredient_id' => $oil->id, 'kind' => 'liquid', 'ml' => 500, 'sequence' => 0]);
        $perfume->formulaLines()->create(['pos_ingredient_id' => $bottle->id, 'kind' => 'packaging', 'qty_per_unit' => 1, 'sequence' => 100]);

        // liquid 500×2 = 1000 over 10 bottles = 100/bottle; packaging 1×0.30 = 0.30.
        $this->assertEqualsWithDelta(100.30, $perfume->fresh()->productionCost(), 0.001);

        // The hint surfaces it under Cost Price (mirrors the recipe-cost hint).
        $hint = $perfume->fresh()->formFieldHint('cost_price');
        $this->assertNotNull($hint);
        $this->assertStringContainsString('Production cost', $hint);

        // A plain product (no formula, no runs, no bottle size) has no hint.
        $plain = PosProduct::query()->create(['name' => 'Soda', 'price' => 1, 'cost_price' => 0]);
        $this->assertNull($plain->productionCost());
        $this->assertNull($plain->formFieldHint('cost_price'));
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

    public function test_container_materials_are_deducted_by_ml(): void
    {
        $this->enableProduction();
        // "3 in hand, each unit is 20 Liter, 24.3 per unit" → 20000 ml each.
        $ethanol = PosIngredient::query()->create([
            'name' => 'Ethanol 20 LTR', 'unit' => 'l', 'pack_size' => 20, 'stock_on_hand' => 3,
            'cost_price' => 24.3,
        ]);
        $this->assertEqualsWithDelta(20000.0, $ethanol->ml_per_unit, 0.001); // derived on save
        $this->assertEqualsWithDelta(60000.0, $ethanol->availableMl(), 0.001);
        $perfume = PosProduct::query()->create(['name' => 'P', 'price' => 5, 'bottle_size_ml' => 50]);

        Livewire::test(ProductionForm::class)
            ->set('product_id', $perfume->id)
            ->set('lines.0.ingredient_id', $ethanol->id)
            ->set('lines.0.ml_used', 1300)
            ->set('produced_units', 26)
            ->call('save')
            ->assertHasNoErrors();

        // 3 − 1300 ÷ 20000 = 2.935 drums left.
        $this->assertEqualsWithDelta(2.935, $ethanol->fresh()->stock_on_hand, 0.0001);

        // Cost = 1300 ml × (24.3 ÷ 20000) = 1.5795 → stored to 3 dp.
        $run = PosProduction::query()->latest('id')->first();
        $this->assertEqualsWithDelta(1.58, $run->total_cost, 0.005);
    }

    public function test_liter_materials_convert_to_ml_automatically(): void
    {
        $this->enableProduction();
        // Just Unit = Liter, stock in liters, no "ml per unit" needed.
        $ethanol = PosIngredient::query()->create(['name' => 'Ethanol', 'unit' => 'l', 'stock_on_hand' => 60, 'cost_price' => 1.215]);
        $perfume = PosProduct::query()->create(['name' => 'P', 'price' => 5, 'bottle_size_ml' => 50]);

        Livewire::test(ProductionForm::class)
            ->set('product_id', $perfume->id)
            ->set('lines.0.ingredient_id', $ethanol->id)
            ->set('lines.0.ml_used', 1300)
            ->set('produced_units', 26)
            ->call('save')
            ->assertHasNoErrors();

        // 60 L − 1.3 L (1300 ml) = 58.7 L.
        $this->assertEqualsWithDelta(58.7, $ethanol->fresh()->stock_on_hand, 0.0001);
        $this->assertEqualsWithDelta(60000.0, $ethanol->availableMl(), 0.001);
    }

    public function test_a_production_cannot_use_more_material_than_is_in_stock(): void
    {
        $this->enableProduction();
        $oil = PosIngredient::query()->create(['name' => 'Oil', 'unit' => 'ml', 'stock_on_hand' => 100, 'cost_price' => 1]);
        $perfume = PosProduct::query()->create(['name' => 'P', 'price' => 5, 'bottle_size_ml' => 50]);

        Livewire::test(ProductionForm::class)
            ->set('product_id', $perfume->id)
            ->set('lines.0.ingredient_id', $oil->id)
            ->set('lines.0.ml_used', 200) // only 100 available
            ->set('produced_units', 4)
            ->call('save')
            ->assertHasErrors('lines.0.ml_used');

        $this->assertSame(0, PosProduction::query()->count());
        $this->assertEqualsWithDelta(100.0, $oil->fresh()->stock_on_hand, 0.001); // untouched
    }

    public function test_a_shortfall_is_recorded_as_variance(): void
    {
        $this->enableProduction();
        $oil = PosIngredient::query()->create(['name' => 'Oil', 'unit' => 'ml', 'stock_on_hand' => 2000, 'cost_price' => 1]);
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

    public function test_reopening_then_re_recording_reverses_then_reapplies_stock(): void
    {
        $this->enableProduction();
        $oil = PosIngredient::query()->create(['name' => 'Oil', 'unit' => 'ml', 'stock_on_hand' => 1000, 'cost_price' => 1]);
        $perfume = PosProduct::query()->create(['name' => 'P', 'price' => 5, 'bottle_size_ml' => 50, 'store_stock' => 0]);

        Livewire::test(ProductionForm::class)
            ->set('product_id', $perfume->id)
            ->set('lines.0.ingredient_id', $oil->id)
            ->set('lines.0.ml_used', 500)
            ->set('produced_units', 10)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertEqualsWithDelta(500.0, $oil->fresh()->stock_on_hand, 0.001); // 1000 − 500
        $this->assertEqualsWithDelta(10.0, $perfume->fresh()->store_stock, 0.001);
        $run = PosProduction::query()->latest('id')->first();

        // A done run can't be edited in place — reopen first (undoes stock → draft).
        Livewire::test(ProductionForm::class, ['id' => $run->id])
            ->assertViewHas('editable', false)
            ->call('reopen')
            ->assertHasNoErrors();
        $this->assertSame('draft', $run->fresh()->state);
        $this->assertEqualsWithDelta(1000.0, $oil->fresh()->stock_on_hand, 0.001); // materials returned
        $this->assertEqualsWithDelta(0.0, $perfume->fresh()->store_stock, 0.001);  // bottles removed

        // Now editable: re-record 800 ml, 16 bottles → applies fresh (200 / 16).
        Livewire::test(ProductionForm::class, ['id' => $run->id])
            ->assertViewHas('editable', true)
            ->set('lines.0.ml_used', 800)
            ->set('produced_units', 16)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertEqualsWithDelta(200.0, $oil->fresh()->stock_on_hand, 0.001); // 1000 − 800
        $this->assertEqualsWithDelta(16.0, $perfume->fresh()->store_stock, 0.001);
        $this->assertSame('done', $run->fresh()->state);
        $this->assertSame(1, PosProduction::query()->count()); // updated, not duplicated
    }

    public function test_a_recorded_run_cannot_be_saved_without_reopening(): void
    {
        $this->enableProduction();
        $oil = PosIngredient::query()->create(['name' => 'Oil', 'unit' => 'ml', 'stock_on_hand' => 1000, 'cost_price' => 1]);
        $perfume = PosProduct::query()->create(['name' => 'P', 'price' => 5, 'bottle_size_ml' => 50, 'store_stock' => 0]);

        Livewire::test(ProductionForm::class)
            ->set('product_id', $perfume->id)
            ->set('lines.0.ingredient_id', $oil->id)
            ->set('lines.0.ml_used', 500)
            ->set('produced_units', 10)
            ->call('save');
        $run = PosProduction::query()->latest('id')->first();

        // A done run rejects a direct save (must reopen first).
        Livewire::test(ProductionForm::class, ['id' => $run->id])
            ->set('produced_units', 99)
            ->call('save')
            ->assertForbidden();

        $this->assertSame(10, $run->fresh()->produced_units); // untouched
    }

    public function test_reversing_a_run_undoes_its_stock_and_locks_it(): void
    {
        $this->enableProduction();
        $oil = PosIngredient::query()->create(['name' => 'Oil', 'unit' => 'ml', 'stock_on_hand' => 1000, 'cost_price' => 1]);
        $perfume = PosProduct::query()->create(['name' => 'P', 'price' => 5, 'bottle_size_ml' => 50, 'store_stock' => 0]);

        Livewire::test(ProductionForm::class)
            ->set('product_id', $perfume->id)
            ->set('lines.0.ingredient_id', $oil->id)
            ->set('lines.0.ml_used', 500)
            ->set('produced_units', 10)
            ->call('save');
        $run = PosProduction::query()->latest('id')->first();

        Livewire::test(ProductionForm::class, ['id' => $run->id])
            ->call('reverse')
            ->assertHasNoErrors();

        $this->assertSame('reversed', $run->fresh()->state);
        $this->assertEqualsWithDelta(1000.0, $oil->fresh()->stock_on_hand, 0.001); // materials back
        $this->assertEqualsWithDelta(0.0, $perfume->fresh()->store_stock, 0.001);  // bottles gone
        $this->assertDatabaseHas('activity_logs', ['action' => 'production_reversed']);
    }

    public function test_an_admin_can_force_reverse_when_bottles_have_left_the_store(): void
    {
        // A run whose output can no longer be returned (sold / over-entered
        // produced count) is normally locked. An admin can force it; store stock
        // floors at zero rather than going negative.
        $this->enableProduction();
        $oil = PosIngredient::query()->create(['name' => 'Oil', 'unit' => 'ml', 'stock_on_hand' => 1000, 'cost_price' => 1]);
        $perfume = PosProduct::query()->create(['name' => 'P', 'price' => 5, 'bottle_size_ml' => 50, 'store_stock' => 0]);

        Livewire::test(ProductionForm::class)
            ->set('product_id', $perfume->id)
            ->set('lines.0.ingredient_id', $oil->id)
            ->set('lines.0.ml_used', 500)
            ->set('produced_units', 10)
            ->call('save');
        $run = PosProduction::query()->latest('id')->first();

        // All 10 bottles move to the shop → the store can't give them back.
        Livewire::test(Productions::class)
            ->set('move_product_id', $perfume->id)
            ->set('move_qty', 10)
            ->call('moveToShop');
        $this->assertTrue($run->fresh()->outputHasLeftStore());

        // Plain reverse is blocked; force reverse (admin) goes through.
        Livewire::test(ProductionForm::class, ['id' => $run->id])
            ->call('reverse')
            ->assertHasErrors('state');
        Livewire::test(ProductionForm::class, ['id' => $run->id])
            ->call('reverse', true)
            ->assertHasNoErrors();

        $this->assertSame('reversed', $run->fresh()->state);
        $this->assertEqualsWithDelta(1000.0, $oil->fresh()->stock_on_hand, 0.001); // materials back
        $this->assertEqualsWithDelta(0.0, $perfume->fresh()->store_stock, 0.001);  // floored at zero
    }

    public function test_a_non_admin_cannot_force_reverse(): void
    {
        $this->enableProduction();
        $oil = PosIngredient::query()->create(['name' => 'Oil', 'unit' => 'ml', 'stock_on_hand' => 1000, 'cost_price' => 1]);
        $perfume = PosProduct::query()->create(['name' => 'P', 'price' => 5, 'bottle_size_ml' => 50, 'store_stock' => 0]);
        Livewire::test(ProductionForm::class)
            ->set('product_id', $perfume->id)
            ->set('lines.0.ingredient_id', $oil->id)
            ->set('lines.0.ml_used', 500)
            ->set('produced_units', 10)
            ->call('save');
        $run = PosProduction::query()->latest('id')->first();
        Livewire::test(Productions::class)
            ->set('move_product_id', $perfume->id)->set('move_qty', 10)->call('moveToShop');

        // A plain user can't reach the production screen at all (it needs
        // pos.product), so the force override is out of reach twice over.
        $this->actingAs(User::factory()->create(['is_admin' => false]));
        Livewire::test(ProductionForm::class, ['id' => $run->id])
            ->assertForbidden();
        $this->assertSame('done', $run->fresh()->state);
    }

    public function test_reversing_is_blocked_when_bottles_have_left_the_store(): void
    {
        $this->enableProduction();
        $oil = PosIngredient::query()->create(['name' => 'Oil', 'unit' => 'ml', 'stock_on_hand' => 1000, 'cost_price' => 1]);
        $perfume = PosProduct::query()->create(['name' => 'P', 'price' => 5, 'bottle_size_ml' => 50, 'store_stock' => 0]);

        Livewire::test(ProductionForm::class)
            ->set('product_id', $perfume->id)
            ->set('lines.0.ingredient_id', $oil->id)
            ->set('lines.0.ml_used', 500)
            ->set('produced_units', 10)
            ->call('save');
        $run = PosProduction::query()->latest('id')->first();

        // Move 4 of the 10 bottles to the shop — the store can no longer give them all back.
        Livewire::test(Productions::class)
            ->set('move_product_id', $perfume->id)
            ->set('move_qty', 4)
            ->call('moveToShop');

        Livewire::test(ProductionForm::class, ['id' => $run->id])
            ->call('reverse')
            ->assertHasErrors('state');

        $this->assertSame('done', $run->fresh()->state); // still recorded, not reversed
    }

    public function test_deleting_a_production_reverses_its_stock(): void
    {
        $this->enableProduction();
        $oil = PosIngredient::query()->create(['name' => 'Oil', 'unit' => 'ml', 'stock_on_hand' => 1000, 'cost_price' => 1]);
        $perfume = PosProduct::query()->create(['name' => 'P', 'price' => 5, 'bottle_size_ml' => 50, 'store_stock' => 0]);

        Livewire::test(ProductionForm::class)
            ->set('product_id', $perfume->id)
            ->set('lines.0.ingredient_id', $oil->id)
            ->set('lines.0.ml_used', 500)
            ->set('produced_units', 10)
            ->call('save');

        $run = PosProduction::query()->latest('id')->first();

        Livewire::test(ProductionForm::class, ['id' => $run->id])
            ->call('delete');

        // Materials restored, store emptied, record gone.
        $this->assertEqualsWithDelta(1000.0, $oil->fresh()->stock_on_hand, 0.001);
        $this->assertEqualsWithDelta(0.0, $perfume->fresh()->store_stock, 0.001);
        $this->assertSame(0, PosProduction::query()->count());
    }

    public function test_the_list_shows_an_edit_link_for_each_production(): void
    {
        $this->enableProduction();
        $oil = PosIngredient::query()->create(['name' => 'Oil', 'unit' => 'ml', 'stock_on_hand' => 1000, 'cost_price' => 1]);
        $perfume = PosProduct::query()->create(['name' => 'P', 'price' => 5, 'bottle_size_ml' => 50, 'store_stock' => 0]);

        Livewire::test(ProductionForm::class)
            ->set('product_id', $perfume->id)
            ->set('lines.0.ingredient_id', $oil->id)
            ->set('lines.0.ml_used', 500)
            ->set('produced_units', 10)
            ->call('save')
            ->assertHasNoErrors();

        $run = PosProduction::query()->latest('id')->firstOrFail();

        Livewire::test(Productions::class)
            ->assertSee(__('Edit'))
            ->assertSeeHtml('/app/pos/production/' . $run->id);
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

    public function test_moving_stock_from_shop_back_to_store(): void
    {
        $this->enableProduction();
        $perfume = PosProduct::query()->create(['name' => 'P', 'price' => 5, 'store_stock' => 0, 'stock_on_hand' => 20]);

        Livewire::test(Productions::class)
            ->set('back_product_id', $perfume->id)
            ->set('back_qty', 8)
            ->call('moveToStore')
            ->assertHasNoErrors();

        $perfume->refresh();
        $this->assertEqualsWithDelta(8.0, $perfume->store_stock, 0.001);   // returned to store
        $this->assertEqualsWithDelta(12.0, $perfume->stock_on_hand, 0.001); // pulled off the shop
        $this->assertSame(PosStockTransfer::SHOP_TO_STORE, PosStockTransfer::query()->latest('id')->first()?->direction);
    }

    public function test_cannot_move_back_more_than_the_shop_holds(): void
    {
        $this->enableProduction();
        $perfume = PosProduct::query()->create(['name' => 'P', 'price' => 5, 'store_stock' => 0, 'stock_on_hand' => 5]);

        Livewire::test(Productions::class)
            ->set('back_product_id', $perfume->id)
            ->set('back_qty', 20)
            ->call('moveToStore')
            ->assertHasErrors('back_qty');

        $this->assertEqualsWithDelta(5.0, $perfume->fresh()->stock_on_hand, 0.001);
        $this->assertEqualsWithDelta(0.0, $perfume->fresh()->store_stock, 0.001);
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

    public function test_a_move_to_shop_can_be_tagged_with_a_production(): void
    {
        $this->enableProduction();
        $perfume = PosProduct::query()->create(['name' => 'P', 'price' => 5, 'store_stock' => 36, 'stock_on_hand' => 0]);
        $run = PosProduction::query()->create([
            'pos_product_id' => $perfume->id, 'bottle_size_ml' => 50, 'total_mix_ml' => 1800,
            'expected_units' => 36, 'produced_units' => 36,
        ]);

        Livewire::test(Productions::class)
            ->set('move_product_id', $perfume->id)
            ->set('move_production_id', $run->id)
            ->set('move_qty', 8)
            ->call('moveToShop')
            ->assertHasNoErrors();

        $transfer = PosStockTransfer::query()->latest('id')->first();
        $this->assertNotNull($transfer);
        $this->assertSame($run->id, $transfer->pos_production_id);
        $this->assertEqualsWithDelta(8.0, $perfume->fresh()->stock_on_hand, 0.001);
    }

    public function test_remove_from_store_takes_bottles_out_without_touching_the_shop(): void
    {
        $this->enableProduction();
        // Happiness: 8 in the store by mistake, 105 already in the shop.
        $perfume = PosProduct::query()->create(['name' => 'Happiness', 'price' => 5, 'store_stock' => 8, 'stock_on_hand' => 105]);

        Livewire::test(Productions::class)
            ->set('remove_product_id', $perfume->id)
            ->set('remove_qty', 8)
            ->call('removeFromStore')
            ->assertHasNoErrors();

        $perfume->refresh();
        $this->assertEqualsWithDelta(0.0, $perfume->store_stock, 0.001);   // the 8 are gone
        $this->assertEqualsWithDelta(105.0, $perfume->stock_on_hand, 0.001); // shop untouched
        $this->assertSame(1, PosStockTransfer::query()->where('direction', PosStockTransfer::STORE_REMOVE)->count());
    }

    public function test_remove_from_store_is_admin_only(): void
    {
        $this->enableProduction();
        $this->actingAs(User::factory()->create(['is_admin' => false]));
        $perfume = PosProduct::query()->create(['name' => 'P', 'price' => 5, 'store_stock' => 8, 'stock_on_hand' => 0]);

        // Blocked at the door: the screen needs pos.product, which a plain user
        // has no grant for — so the removal can't be reached.
        Livewire::test(Productions::class)->assertForbidden();

        $this->assertEqualsWithDelta(8.0, $perfume->fresh()->store_stock, 0.001); // untouched
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

    public function test_the_pos_app_menu_links_to_production_when_enabled(): void
    {
        Livewire::test(\App\Livewire\Navigation\AppSwitcher::class)
            ->assertDontSee('Production &amp; store');

        $this->enableProduction();
        Livewire::test(\App\Livewire\Navigation\AppSwitcher::class)
            ->assertSee('Production &amp; store', false);
    }

    public function test_the_product_page_shortcut_preselects_the_product(): void
    {
        $this->enableProduction();
        $perfume = PosProduct::query()->create(['name' => 'PreselectMe', 'price' => 5, 'bottle_size_ml' => 50]);

        Livewire::withQueryParams(['product' => $perfume->id])
            ->test(ProductionForm::class)
            ->assertSet('product_id', $perfume->id);
    }

    public function test_the_production_screens_are_gated_to_the_feature(): void
    {
        // Off by default → the route 404s (the component aborts in mount()).
        $this->get('/app/pos/production')->assertNotFound();

        // On → the component mounts and renders.
        $this->enableProduction();
        Livewire::test(Productions::class)->assertSee('Production & store');
    }

    public function test_packaging_is_deducted_per_bottle_and_added_to_cost(): void
    {
        $this->enableProduction();
        $oil = PosIngredient::query()->create(['name' => 'Oil', 'unit' => 'ml', 'stock_on_hand' => 1000, 'cost_price' => 2]);
        $bottle = PosIngredient::query()->create(['name' => 'Bottle 50ml', 'unit' => 'pcs', 'stock_on_hand' => 50, 'cost_price' => 0.30]);
        $cap = PosIngredient::query()->create(['name' => 'Cap', 'unit' => 'pcs', 'stock_on_hand' => 60, 'cost_price' => 0.10]);
        $perfume = PosProduct::query()->create(['name' => 'P', 'price' => 5, 'bottle_size_ml' => 50, 'store_stock' => 0]);

        Livewire::test(ProductionForm::class)
            ->set('product_id', $perfume->id)
            ->set('lines.0.ingredient_id', $oil->id)
            ->set('lines.0.ml_used', 500)
            ->call('addPackaging')
            ->set('packaging.0.ingredient_id', $bottle->id)
            ->set('packaging.0.qty', 1)
            ->call('addPackaging')
            ->set('packaging.1.ingredient_id', $cap->id)
            ->set('packaging.1.qty', 1)
            ->set('produced_units', 10)
            ->call('save')
            ->assertHasNoErrors();

        // Liquid by ml, packaging by whole units × bottles produced.
        $this->assertEqualsWithDelta(500.0, $oil->fresh()->stock_on_hand, 0.001);
        $this->assertEqualsWithDelta(40.0, $bottle->fresh()->stock_on_hand, 0.001); // 50 − 10
        $this->assertEqualsWithDelta(50.0, $cap->fresh()->stock_on_hand, 0.001);    // 60 − 10
        $this->assertEqualsWithDelta(10.0, $perfume->fresh()->store_stock, 0.001);

        // Cost = 500×2 (oil) + 10×0.30 (bottle) + 10×0.10 (cap) = 1004.
        $run = PosProduction::query()->latest('id')->first();
        $this->assertEqualsWithDelta(1004.0, $run->total_cost, 0.001);
        $this->assertEqualsWithDelta(100.4, $perfume->fresh()->cost_price, 0.01);
    }

    public function test_packaging_short_stock_blocks_the_production(): void
    {
        $this->enableProduction();
        $oil = PosIngredient::query()->create(['name' => 'Oil', 'unit' => 'ml', 'stock_on_hand' => 1000, 'cost_price' => 1]);
        $bottle = PosIngredient::query()->create(['name' => 'Bottle', 'unit' => 'pcs', 'stock_on_hand' => 5, 'cost_price' => 0.30]);
        $perfume = PosProduct::query()->create(['name' => 'P', 'price' => 5, 'bottle_size_ml' => 50, 'store_stock' => 0]);

        Livewire::test(ProductionForm::class)
            ->set('product_id', $perfume->id)
            ->set('lines.0.ingredient_id', $oil->id)
            ->set('lines.0.ml_used', 500)
            ->call('addPackaging')
            ->set('packaging.0.ingredient_id', $bottle->id)
            ->set('packaging.0.qty', 1) // needs 10, only 5 in stock
            ->set('produced_units', 10)
            ->call('save')
            ->assertHasErrors('packaging.0.qty');

        $this->assertSame(0, PosProduction::query()->count());
        $this->assertEqualsWithDelta(5.0, $bottle->fresh()->stock_on_hand, 0.001);   // untouched
        $this->assertEqualsWithDelta(1000.0, $oil->fresh()->stock_on_hand, 0.001);   // untouched
    }

    public function test_a_saved_formula_auto_fills_packaging(): void
    {
        $this->enableProduction();
        $oil = PosIngredient::query()->create(['name' => 'Oil', 'unit' => 'ml', 'stock_on_hand' => 1000, 'cost_price' => 2]);
        $bottle = PosIngredient::query()->create(['name' => 'Bottle', 'unit' => 'pcs', 'stock_on_hand' => 100, 'cost_price' => 0.30]);
        $perfume = PosProduct::query()->create(['name' => 'PF', 'price' => 5, 'bottle_size_ml' => 50]);

        Livewire::test(ProductionForm::class)
            ->set('product_id', $perfume->id)
            ->set('lines.0.ingredient_id', $oil->id)
            ->set('lines.0.ml_used', 500)
            ->call('addPackaging')
            ->set('packaging.0.ingredient_id', $bottle->id)
            ->set('packaging.0.qty', 2)
            ->call('saveAsFormula')
            ->assertSet('formulaJustSaved', true);

        $this->assertSame(2, $perfume->formulaLines()->count()); // one liquid + one packaging

        // Re-opening splits the formula back into liquid lines + packaging.
        Livewire::test(ProductionForm::class)
            ->set('product_id', $perfume->id)
            ->assertCount('lines', 1)
            ->assertCount('packaging', 1)
            ->assertSet('lines.0.ml_used', '500')
            ->assertSet('packaging.0.ingredient_id', (string) $bottle->id)
            ->assertSet('packaging.0.qty', '2');
    }

    public function test_picking_a_product_does_not_wipe_manually_added_packaging(): void
    {
        $this->enableProduction();
        $oil = PosIngredient::query()->create(['name' => 'Oil', 'unit' => 'ml', 'stock_on_hand' => 1000, 'cost_price' => 2]);
        $bottle = PosIngredient::query()->create(['name' => 'Bottle', 'unit' => 'pcs', 'stock_on_hand' => 100, 'cost_price' => 0.30]);
        $perfume = PosProduct::query()->create(['name' => 'PF', 'price' => 5, 'bottle_size_ml' => 50]);

        // A LIQUID-ONLY formula (no packaging declared).
        Livewire::test(ProductionForm::class)
            ->set('product_id', $perfume->id)
            ->set('lines.0.ingredient_id', $oil->id)
            ->set('lines.0.ml_used', 500)
            ->call('saveAsFormula');
        $this->assertSame(1, $perfume->formulaLines()->count());

        // Add packaging by hand, then re-trigger the product auto-fill (by
        // re-selecting the product): a liquid-only formula must NOT wipe the
        // manually-added packaging.
        Livewire::test(ProductionForm::class)
            ->set('product_id', $perfume->id)
            ->call('addPackaging')
            ->set('packaging.0.ingredient_id', $bottle->id)
            ->set('packaging.0.qty', 1)
            ->set('product_id', null)          // fires updatedProductId (skips)
            ->set('product_id', $perfume->id)  // fires again → must NOT wipe
            ->assertCount('packaging', 1);
    }

    public function test_a_reopened_run_keeps_its_packaging(): void
    {
        $this->enableProduction();
        $oil = PosIngredient::query()->create(['name' => 'Oil', 'unit' => 'ml', 'stock_on_hand' => 2000, 'cost_price' => 1]);
        $bottle = PosIngredient::query()->create(['name' => 'Bottle', 'unit' => 'pcs', 'stock_on_hand' => 50, 'cost_price' => 0.30]);
        $perfume = PosProduct::query()->create(['name' => 'P', 'price' => 5, 'bottle_size_ml' => 50]);

        Livewire::test(ProductionForm::class)
            ->set('product_id', $perfume->id)
            ->set('lines.0.ingredient_id', $oil->id)
            ->set('lines.0.ml_used', 500)
            ->call('addPackaging')
            ->set('packaging.0.ingredient_id', $bottle->id)
            ->set('packaging.0.qty', 1)
            ->set('produced_units', 10)
            ->call('save');
        $run = PosProduction::query()->latest('id')->first();

        // Reopen, then re-trigger the product field (re-select) — the run's
        // packaging must survive: the formula auto-fill is skipped for an
        // existing run, so re-recording keeps the packaging it was made with.
        Livewire::test(ProductionForm::class, ['id' => $run->id])->call('reopen');
        Livewire::test(ProductionForm::class, ['id' => $run->id])
            ->assertCount('packaging', 1)
            ->set('product_id', null)
            ->set('product_id', $perfume->id)
            ->assertCount('packaging', 1)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, $run->fresh()->lines()->where('kind', PosProductionLine::KIND_PACKAGING)->count());
    }

    public function test_the_production_pickers_group_materials_by_category(): void
    {
        $this->enableProduction();
        $oils = PosIngredientCategory::query()->create(['name' => 'CatOils', 'sequence' => 1]);
        $bottles = PosIngredientCategory::query()->create(['name' => 'CatBottles', 'sequence' => 2]);
        PosIngredient::query()->create(['name' => 'Rose oil', 'unit' => 'ml', 'stock_on_hand' => 100, 'cost_price' => 30, 'pos_ingredient_category_id' => $oils->id]);
        PosIngredient::query()->create(['name' => 'Bottle 50', 'unit' => 'pcs', 'stock_on_hand' => 100, 'cost_price' => 0.3, 'pos_ingredient_category_id' => $bottles->id]);
        PosIngredient::query()->create(['name' => 'Loose material', 'unit' => 'ml', 'stock_on_hand' => 100, 'cost_price' => 1]); // no category

        Livewire::test(ProductionForm::class)
            ->assertSee('CatOils')        // optgroup labels
            ->assertSee('CatBottles')
            ->assertSee('Uncategorised')  // fallback bucket
            ->assertSee('Rose oil')
            ->assertSee('Bottle 50');
    }

    public function test_editing_reverses_and_reapplies_packaging(): void
    {
        $this->enableProduction();
        $oil = PosIngredient::query()->create(['name' => 'Oil', 'unit' => 'ml', 'stock_on_hand' => 2000, 'cost_price' => 1]);
        $bottle = PosIngredient::query()->create(['name' => 'Bottle', 'unit' => 'pcs', 'stock_on_hand' => 50, 'cost_price' => 0.30]);
        $perfume = PosProduct::query()->create(['name' => 'P', 'price' => 5, 'bottle_size_ml' => 50, 'store_stock' => 0]);

        Livewire::test(ProductionForm::class)
            ->set('product_id', $perfume->id)
            ->set('lines.0.ingredient_id', $oil->id)
            ->set('lines.0.ml_used', 500)
            ->call('addPackaging')
            ->set('packaging.0.ingredient_id', $bottle->id)
            ->set('packaging.0.qty', 1)
            ->set('produced_units', 10)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertEqualsWithDelta(40.0, $bottle->fresh()->stock_on_hand, 0.001); // 50 − 10
        $run = PosProduction::query()->latest('id')->first();

        // Reopen (packaging returned → 50), then re-record 20 bottles → 30 left.
        Livewire::test(ProductionForm::class, ['id' => $run->id])->call('reopen');
        $this->assertEqualsWithDelta(50.0, $bottle->fresh()->stock_on_hand, 0.001);

        Livewire::test(ProductionForm::class, ['id' => $run->id])
            ->assertCount('packaging', 1)
            ->set('lines.0.ml_used', 1000)
            ->set('produced_units', 20)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertEqualsWithDelta(30.0, $bottle->fresh()->stock_on_hand, 0.001); // 50 − 20
        $this->assertEqualsWithDelta(20.0, $perfume->fresh()->store_stock, 0.001);
        $this->assertSame(1, PosProduction::query()->count());
    }

    public function test_correcting_a_material_cost_re_derives_the_perfume_cost(): void
    {
        $this->enableProduction();
        $ethanol = PosIngredient::query()->create(['name' => 'Ethanol', 'unit' => 'ml', 'stock_on_hand' => 100000, 'cost_price' => 0.01]);
        $perfume = PosProduct::query()->create(['name' => 'P', 'price' => 5, 'bottle_size_ml' => 50, 'cost_price' => 0]);

        // 1000 ml at 0.01/ml over 20 bottles → 0.50 per bottle.
        Livewire::test(ProductionForm::class)
            ->set('product_id', $perfume->id)
            ->set('lines.0.ingredient_id', $ethanol->id)
            ->set('lines.0.ml_used', 1000)
            ->set('produced_units', 20)
            ->call('save')
            ->assertHasNoErrors();
        $this->assertEqualsWithDelta(0.5, $perfume->fresh()->cost_price, 0.001);

        // Manager fixes the ethanol cost to 0.02/ml — the perfume cost follows.
        $ethanol->update(['cost_price' => 0.02]);

        $this->assertEqualsWithDelta(1.0, $perfume->fresh()->cost_price, 0.001);
    }

    public function test_the_recompute_costs_button_re_derives_costs_and_is_admin_only(): void
    {
        $this->enableProduction();
        $ethanol = PosIngredient::query()->create(['name' => 'Ethanol', 'unit' => 'ml', 'stock_on_hand' => 100000, 'cost_price' => 0.02]);
        $perfume = PosProduct::query()->create(['name' => 'P', 'price' => 5, 'bottle_size_ml' => 50]);
        Livewire::test(ProductionForm::class)
            ->set('product_id', $perfume->id)
            ->set('lines.0.ingredient_id', $ethanol->id)
            ->set('lines.0.ml_used', 1000)
            ->set('produced_units', 20)
            ->call('save');

        // Drift the stored cost (as if recorded at a wrong ethanol price), quietly
        // so the auto-cascade doesn't fix it first.
        $perfume->cost_price = 99;
        $perfume->saveQuietly();

        Livewire::test(Productions::class)->call('recomputeCosts')->assertHasNoErrors();
        $this->assertEqualsWithDelta(1.0, $perfume->fresh()->cost_price, 0.001); // (1000×0.02)/20

        // Non-admin can't even open the screen (it needs pos.product).
        $this->actingAs(User::factory()->create(['is_admin' => false]));
        Livewire::test(Productions::class)->assertForbidden();
    }

    public function test_a_run_without_packaging_falls_back_to_the_formula_packaging(): void
    {
        // A batch recorded with no packaging lines (older runs / runs that can't
        // be reopened) must still count the product's standard bottle/cap so the
        // cost isn't understated. The packaging comes from the product's formula.
        $this->enableProduction();
        $oil = PosIngredient::query()->create(['name' => 'Oil', 'unit' => 'ml', 'stock_on_hand' => 100000, 'cost_price' => 0.01]);
        $bottle = PosIngredient::query()->create(['name' => 'Bottle', 'unit' => 'pcs', 'stock_on_hand' => 1000, 'cost_price' => 0.20]);
        $perfume = PosProduct::query()->create(['name' => 'P', 'price' => 5, 'bottle_size_ml' => 50, 'cost_price' => 0]);

        // The product's standard packaging: one bottle @ 0.20 each.
        $perfume->formulaLines()->create(['pos_ingredient_id' => $bottle->id, 'kind' => 'packaging', 'qty_per_unit' => 1, 'sequence' => 100]);

        // Record a run with liquid only, no packaging: 1000 ml @ 0.01/ml over 20
        // bottles → 0.50 materials. Packaging must add 0.20 → 0.70 per bottle.
        Livewire::test(ProductionForm::class)
            ->set('product_id', $perfume->id)
            ->set('lines.0.ingredient_id', $oil->id)
            ->set('lines.0.ml_used', 1000)
            ->set('produced_units', 20)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertEqualsWithDelta(0.70, $perfume->fresh()->productionCost(), 0.001);
    }

    public function test_a_run_with_its_own_packaging_is_not_double_counted(): void
    {
        // When a run DID record packaging, the formula fallback must not fire —
        // the run's own packaging is authoritative.
        $this->enableProduction();
        $oil = PosIngredient::query()->create(['name' => 'Oil', 'unit' => 'ml', 'stock_on_hand' => 100000, 'cost_price' => 0.01]);
        $bottle = PosIngredient::query()->create(['name' => 'Bottle', 'unit' => 'pcs', 'stock_on_hand' => 1000, 'cost_price' => 0.20]);
        $perfume = PosProduct::query()->create(['name' => 'P', 'price' => 5, 'bottle_size_ml' => 50, 'cost_price' => 0]);
        $perfume->formulaLines()->create(['pos_ingredient_id' => $bottle->id, 'kind' => 'packaging', 'qty_per_unit' => 1, 'sequence' => 100]);

        // Run records its own packaging (2 bottles/unit @ 0.20 → 0.40), liquid
        // 0.50 → 0.90. The formula's 0.20 must NOT be added on top.
        Livewire::test(ProductionForm::class)
            ->set('product_id', $perfume->id)
            ->set('lines.0.ingredient_id', $oil->id)
            ->set('lines.0.ml_used', 1000)
            ->set('packaging.0.ingredient_id', $bottle->id)
            ->set('packaging.0.qty', 2)
            ->set('produced_units', 20)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertEqualsWithDelta(0.90, $perfume->fresh()->productionCost(), 0.001);
    }

    public function test_reversing_a_run_stops_it_driving_the_cost(): void
    {
        // An offer built from a perfume must price that perfume off its real
        // (Done) batch — never off a run that was reversed/reopened. Otherwise
        // the offer's component cost disagrees with the production screen.
        $this->enableProduction();
        $oil = PosIngredient::query()->create(['name' => 'Oil', 'unit' => 'ml', 'stock_on_hand' => 1000, 'cost_price' => 1]);
        $perfume = PosProduct::query()->create(['name' => 'P', 'price' => 5, 'bottle_size_ml' => 50, 'store_stock' => 0]);

        // 500 ml at 1/ml over 10 bottles → 50.0 per bottle (a real Done run).
        Livewire::test(ProductionForm::class)
            ->set('product_id', $perfume->id)
            ->set('lines.0.ingredient_id', $oil->id)
            ->set('lines.0.ml_used', 500)
            ->set('produced_units', 10)
            ->call('save');
        $run = PosProduction::query()->latest('id')->first();

        $this->assertEqualsWithDelta(50.0, $perfume->fresh()->productionCost(), 0.001);

        // Reverse it. This perfume has no saved FORMULA, so with the only run
        // undone there is nothing to cost from — productionCost() must go null,
        // proving the reversed run's 50.0 is no longer used.
        Livewire::test(ProductionForm::class, ['id' => $run->id])
            ->call('reverse')
            ->assertHasNoErrors();

        $this->assertSame('reversed', $run->fresh()->state);
        $this->assertNull($perfume->fresh()->productionCost());
        $this->assertNotContains(
            $perfume->id,
            PosProduction::query()->where('state', PosProduction::STATE_DONE)->pluck('pos_product_id')->all(),
            'A reversed run must not count as a produced perfume for cost recompute.',
        );
    }
}
