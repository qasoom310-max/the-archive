<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Modules\Pos\Models\PosIngredient;
use Modules\Purchases\Enums\PurchaseState;
use Modules\Purchases\Livewire\PurchaseForm;
use Modules\Purchases\Models\Purchase;
use Modules\Purchases\Models\PurchaseLine;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * Saving a purchase must be ATOMIC.
 *
 * The editor rewrites a bill's lines as DELETE-then-re-INSERT. That ran outside
 * a transaction, so the delete committed on its own: anything that threw while
 * re-inserting (a bad value, a constraint, any 500 mid-request) left the bill
 * saved with ZERO lines — a whole day of purchase entry silently wiped, with the
 * header still sitting there looking normal.
 */
final class PurchaseSaveIsAtomicTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('pos');
        app(ModuleManager::class)->install('purchases');
    }

    private function billWithTwoLines(): Purchase
    {
        $purchase = Purchase::query()->create([
            'reference' => 'BILL/0001',
            'date' => now()->toDateString(),
            'state' => PurchaseState::Draft->value,
            'is_stock_purchase' => true,
        ]);

        $oud = PosIngredient::query()->create(['name' => 'Oud oil', 'cost_price' => 5, 'stock_on_hand' => 0]);
        $musk = PosIngredient::query()->create(['name' => 'Musk', 'cost_price' => 3, 'stock_on_hand' => 0]);

        $purchase->lines()->create(['pos_ingredient_id' => $oud->id, 'description' => 'Oud oil', 'quantity' => 4, 'unit_cost' => 5]);
        $purchase->lines()->create(['pos_ingredient_id' => $musk->id, 'description' => 'Musk', 'quantity' => 2, 'unit_cost' => 3]);

        return $purchase;
    }

    public function test_a_failure_while_rewriting_the_lines_leaves_the_saved_lines_intact(): void
    {
        $purchase = $this->billWithTwoLines();
        $this->assertSame(2, $purchase->lines()->count());

        // Blow up mid-rewrite, exactly as a bad value or a 500 would.
        $hook = 'eloquent.creating: ' . PurchaseLine::class;
        Event::listen($hook, static function (): void {
            throw new RuntimeException('boom while re-inserting a line');
        });

        $exploded = false;
        try {
            Livewire::test(PurchaseForm::class, ['id' => $purchase->id])->call('save');
        } catch (Throwable) {
            $exploded = true;
        } finally {
            Event::forget($hook);
        }

        $this->assertTrue($exploded, 'The save was expected to fail.');

        // The DELETE must have rolled back with the failed INSERT — a day of
        // entry is NOT wiped just because the save errored.
        $this->assertSame(2, $purchase->fresh()?->lines()->count());
    }

    public function test_a_normal_save_still_rewrites_the_lines(): void
    {
        $purchase = $this->billWithTwoLines();

        Livewire::test(PurchaseForm::class, ['id' => $purchase->id])
            ->set('lines.0.quantity', 9)
            ->call('save')
            ->assertHasNoErrors();

        $lines = $purchase->fresh()?->lines;
        $this->assertNotNull($lines);
        // Both lines survive the rewrite, and the edit landed.
        $this->assertCount(2, $lines);
        $this->assertContains(9.0, $lines->map(static fn (PurchaseLine $l): float => (float) $l->quantity)->all());
    }
}
