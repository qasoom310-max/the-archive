<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Business\Feature;
use App\Erp\Business\Features;
use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Pos\Enums\IngredientMoveKind;
use Modules\Pos\Livewire\ProductionForm;
use Modules\Pos\Models\PosIngredient;
use Modules\Pos\Models\PosIngredientMove;
use Modules\Pos\Models\PosProduct;
use Modules\Pos\Models\PosProduction;
use Tests\TestCase;

/**
 * "why do we make a new production and the ethanol stock is the same?"
 *
 * Chasing that report through the real ProductionForm, not the model directly —
 * the whole point is to prove (or disprove) that the screen a user actually
 * clicks deducts a liquid material, on a SECOND run as well as a first, and in
 * the units a perfume shop really stores ethanol in (litre drums, not ml).
 *
 * Each case asserts the ledger too: if stock moved without a move row, the two
 * would silently drift apart and "how much did we use" would lie.
 */
final class PosProductionEthanolDeductionTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('pos');
        Features::setOverrides([Feature::Production->value => true]);
    }

    private function perfume(): PosProduct
    {
        return PosProduct::query()->create([
            'name' => '3meeq', 'price' => 20, 'bottle_size_ml' => 50,
            'stock_on_hand' => 0, 'store_stock' => 0,
        ]);
    }

    /** Run one production of `$ml` of `$ingredient` into `$product`. */
    private function produce(PosProduct $product, PosIngredient $ingredient, float $ml, int $units): void
    {
        Livewire::test(ProductionForm::class)
            ->set('product_id', $product->id)
            ->set('lines.0.ingredient_id', $ingredient->id)
            ->set('lines.0.ml_used', $ml)
            ->set('produced_units', $units)
            ->call('save')
            ->assertHasNoErrors();
    }

    public function test_two_consecutive_productions_each_deduct_ethanol(): void
    {
        // The reported shape: it is the SECOND run that looked like a no-op.
        $product = $this->perfume();
        $ethanol = PosIngredient::query()->create([
            'name' => 'Ethanol', 'unit' => 'ml', 'pack_size' => 1,
            'stock_on_hand' => 5000, 'cost_price' => 0.01,
        ]);

        $this->produce($product, $ethanol, 1300, 26);
        $this->assertEqualsWithDelta(3700.0, (float) $ethanol->fresh()?->stock_on_hand, 0.001);

        $this->produce($product, $ethanol, 1300, 26);
        $this->assertEqualsWithDelta(
            2400.0,
            (float) $ethanol->fresh()?->stock_on_hand,
            0.001,
            'A second production must deduct as well as the first.',
        );

        $this->assertSame(2, PosProduction::query()->count());
        $this->assertSame(2, PosIngredientMove::query()->where('pos_ingredient_id', $ethanol->id)->count());
        $this->assertEqualsWithDelta(2600.0, $ethanol->fresh()?->usedTotal() ?? 0.0, 0.001);
    }

    public function test_ethanol_stored_in_litre_drums_is_deducted_proportionally(): void
    {
        // How a perfume shop really holds it: 5 drums of 20 L. Using 1300 ml is
        // 0.065 of a drum — a real deduction, but a small enough fraction that
        // it can LOOK like nothing moved if you only glance at the whole number.
        $product = $this->perfume();
        $ethanol = PosIngredient::query()->create([
            'name' => 'Ethanol', 'unit' => 'l', 'pack_size' => 20,
            'stock_on_hand' => 5, 'cost_price' => 30,
        ]);

        $this->assertEqualsWithDelta(20000.0, $ethanol->fresh()?->mlPerUnit() ?? 0.0, 0.001);

        $this->produce($product, $ethanol, 1300, 26);

        $this->assertEqualsWithDelta(4.935, (float) $ethanol->fresh()?->stock_on_hand, 0.001);

        $move = PosIngredientMove::query()->where('pos_ingredient_id', $ethanol->id)->latest('id')->first();
        $this->assertNotNull($move);
        $this->assertSame(IngredientMoveKind::Production, $move->kind);
        $this->assertEqualsWithDelta(-0.065, $move->qty, 0.001);
    }

    public function test_editing_a_production_reverses_then_reapplies_the_deduction(): void
    {
        $product = $this->perfume();
        $ethanol = PosIngredient::query()->create([
            'name' => 'Ethanol', 'unit' => 'ml', 'pack_size' => 1,
            'stock_on_hand' => 5000, 'cost_price' => 0.01,
        ]);

        $this->produce($product, $ethanol, 1000, 20);
        $this->assertEqualsWithDelta(4000.0, (float) $ethanol->fresh()?->stock_on_hand, 0.001);

        $run = PosProduction::query()->latest('id')->firstOrFail();

        // Re-open that run and raise the mix: net effect must be −1500, not
        // −1000 −1500 (double count) and not −500 (partial reverse).
        Livewire::test(ProductionForm::class, ['id' => $run->id])
            ->set('lines.0.ml_used', 1500)
            ->set('produced_units', 30)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertEqualsWithDelta(3500.0, (float) $ethanol->fresh()?->stock_on_hand, 0.001);
    }

    public function test_a_run_short_of_stock_is_refused_and_deducts_nothing(): void
    {
        $product = $this->perfume();
        $ethanol = PosIngredient::query()->create([
            'name' => 'Ethanol', 'unit' => 'ml', 'pack_size' => 1,
            'stock_on_hand' => 100, 'cost_price' => 0.01,
        ]);

        Livewire::test(ProductionForm::class)
            ->set('product_id', $product->id)
            ->set('lines.0.ingredient_id', $ethanol->id)
            ->set('lines.0.ml_used', 5000)
            ->set('produced_units', 100)
            ->call('save')
            ->assertHasErrors();

        $this->assertEqualsWithDelta(100.0, (float) $ethanol->fresh()?->stock_on_hand, 0.001);
        $this->assertSame(0, PosIngredientMove::query()->where('pos_ingredient_id', $ethanol->id)->count());
    }

    public function test_a_non_volume_unit_treats_millilitres_as_whole_units(): void
    {
        // A configuration trap worth pinning: ml_per_unit is only derived for
        // 'l' and 'ml'. Set a liquid to a counting unit and one ml of recipe
        // consumes one whole unit of stock — a 1300 ml mix wipes out 1300 of
        // them. Documented here so the behaviour is deliberate and visible
        // rather than discovered on a live shop.
        $product = $this->perfume();
        $ethanol = PosIngredient::query()->create([
            'name' => 'Ethanol (mis-configured)', 'unit' => 'qty', 'pack_size' => 1,
            'stock_on_hand' => 5000, 'cost_price' => 0.01,
        ]);

        $this->assertNull($ethanol->fresh()?->ml_per_unit);

        $this->produce($product, $ethanol, 1300, 26);

        $this->assertEqualsWithDelta(3700.0, (float) $ethanol->fresh()?->stock_on_hand, 0.001);
    }
}
