<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Business\Feature;
use App\Erp\Business\Features;
use App\Erp\Modules\ModuleManager;
use App\Livewire\Views\FormView;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Pos\Enums\IngredientMoveKind;
use Modules\Pos\Livewire\PosStockReport;
use Modules\Pos\Livewire\ProductionForm;
use Modules\Pos\Models\PosIngredient;
use Modules\Pos\Models\PosIngredientMove;
use Modules\Pos\Models\PosProduct;
use Tests\TestCase;

/**
 * Raw-material stock is a ledger, not a number someone types.
 *
 * Reported 2026-08-09: "we made a production and it's not giving minus in the
 * ingredients stock". Production WAS deducting — but the ingredient form
 * auto-saves on every keystroke and wrote back every field it had loaded,
 * including the on-hand read when the page opened. A form left open across a
 * production flushed the old figure over the deduction, so the stock appeared
 * never to move. Nothing recorded what had consumed what, so it was impossible
 * to tell a real deduction from an overwrite.
 *
 * Two halves, both pinned here: stock is read-only on the form (so it cannot be
 * overwritten), and every movement is recorded (so purchased vs used is
 * answerable).
 */
final class PosIngredientStockLedgerTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('pos');
    }

    public function test_the_ingredient_form_cannot_write_stock_on_hand(): void
    {
        // The regression itself. The form loads 2520, something consumes 100
        // behind its back, and then a keystroke saves. The 2420 must survive.
        $bottle = PosIngredient::query()->create([
            'name' => '50ML CLEAR BOTTLE', 'unit' => 'pcs', 'stock_on_hand' => 2520, 'cost_price' => 0.189,
        ]);

        $form = Livewire::test(FormView::class, [
            'model' => PosIngredient::class,
            'modelKey' => 'pos.ingredient',
            'recordId' => $bottle->id,
        ]);

        $bottle->applyStockDelta(-100, IngredientMoveKind::Production, 'Production #1');

        $form->set('form.name', '50ML CLEAR BOTTLE v2')->call('save');

        $this->assertEqualsWithDelta(
            2420.0,
            (float) $bottle->fresh()?->stock_on_hand,
            0.001,
            'A stale form save must not overwrite stock that moved meanwhile.',
        );
    }

    public function test_a_production_run_records_what_it_consumed(): void
    {
        Features::setOverrides([Feature::Production->value => true]);

        $oil = PosIngredient::query()->create(['name' => 'Oil', 'unit' => 'ml', 'stock_on_hand' => 1000, 'cost_price' => 2]);
        $perfume = PosProduct::query()->create([
            'name' => 'Perfume X', 'price' => 5, 'bottle_size_ml' => 50, 'stock_on_hand' => 0, 'store_stock' => 0,
        ]);

        Livewire::test(ProductionForm::class)
            ->set('product_id', $perfume->id)
            ->set('lines.0.ingredient_id', $oil->id)
            ->set('lines.0.ml_used', 500)
            ->set('produced_units', 10)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertEqualsWithDelta(500.0, (float) $oil->fresh()?->stock_on_hand, 0.001);

        $move = PosIngredientMove::query()->where('pos_ingredient_id', $oil->id)->latest('id')->first();
        $this->assertNotNull($move, 'A production must leave a trace in the ingredient ledger.');
        $this->assertSame(IngredientMoveKind::Production, $move->kind);
        $this->assertEqualsWithDelta(-500.0, $move->qty, 0.001);
        $this->assertEqualsWithDelta(500.0, $move->balance_after, 0.001);

        $this->assertEqualsWithDelta(500.0, $oil->fresh()?->usedTotal() ?? 0.0, 0.001);
    }

    public function test_purchased_and_used_are_reported_separately(): void
    {
        $bottle = PosIngredient::query()->create(['name' => 'Bottle', 'unit' => 'pcs', 'stock_on_hand' => 0]);

        $bottle->applyStockDelta(1000, IngredientMoveKind::Purchase, 'Purchase P/0001');
        $bottle->applyStockDelta(-120, IngredientMoveKind::Production, 'Production #1');
        $bottle->applyStockDelta(-30, IngredientMoveKind::Sale, 'POS sale POS/1/0001');
        $bottle->applyStockDelta(-10, IngredientMoveKind::Damage, 'Damage D/1');

        $this->assertEqualsWithDelta(1000.0, $bottle->purchasedTotal(), 0.001);
        $this->assertEqualsWithDelta(160.0, $bottle->usedTotal(), 0.001); // 120 + 30 + 10
        $this->assertEqualsWithDelta(840.0, (float) $bottle->fresh()?->stock_on_hand, 0.001);
    }

    public function test_a_recount_is_neither_a_purchase_nor_usage(): void
    {
        // A re-count corrects the number; treating it as a purchase would
        // overstate spend, and as usage would overstate consumption.
        $bottle = PosIngredient::query()->create(['name' => 'Bottle', 'unit' => 'pcs', 'stock_on_hand' => 100]);

        Livewire::test(PosStockReport::class)
            ->call('openAdjust', $bottle->id, 'ingredient')
            ->set('adjustQty', '90')
            ->call('saveAdjust');

        $this->assertEqualsWithDelta(90.0, (float) $bottle->fresh()?->stock_on_hand, 0.001);
        $this->assertEqualsWithDelta(0.0, $bottle->purchasedTotal(), 0.001);
        $this->assertEqualsWithDelta(0.0, $bottle->usedTotal(), 0.001);

        $move = PosIngredientMove::query()->where('pos_ingredient_id', $bottle->id)->latest('id')->first();
        $this->assertNotNull($move);
        $this->assertSame(IngredientMoveKind::Adjustment, $move->kind);
        $this->assertEqualsWithDelta(-10.0, $move->qty, 0.001);
    }

    public function test_the_ledger_sums_back_to_the_on_hand_figure(): void
    {
        // The property that makes drift detectable: replay the moves and you
        // land on stock_on_hand.
        $ing = PosIngredient::query()->create(['name' => 'Ethanol', 'unit' => 'l', 'stock_on_hand' => 0]);

        $ing->applyStockDelta(50, IngredientMoveKind::Purchase, 'P/1');
        $ing->applyStockDelta(-12.5, IngredientMoveKind::Production, 'Production #1');
        $ing->applyStockDelta(2.5, IngredientMoveKind::Adjustment, 'recount');

        $sum = (float) PosIngredientMove::query()->where('pos_ingredient_id', $ing->id)->sum('qty');

        $this->assertEqualsWithDelta((float) $ing->fresh()?->stock_on_hand, $sum, 0.001);
    }

    public function test_a_zero_move_is_not_recorded(): void
    {
        $ing = PosIngredient::query()->create(['name' => 'Oil', 'unit' => 'ml', 'stock_on_hand' => 10]);

        $ing->applyStockDelta(0, IngredientMoveKind::Adjustment, 'no change');

        $this->assertSame(0, PosIngredientMove::query()->where('pos_ingredient_id', $ing->id)->count());
    }
}
