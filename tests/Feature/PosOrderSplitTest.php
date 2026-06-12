<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Pos\Enums\OrderState;
use Modules\Pos\Enums\SessionState;
use Modules\Pos\Exceptions\PosOrderSplitException;
use Modules\Pos\Http\Controllers\PosReceiptPrintController;
use Modules\Pos\Livewire\PosOrders;
use Modules\Pos\Livewire\SplitOrderModal;
use Modules\Pos\Models\PosFloor;
use Modules\Pos\Models\PosOrder;
use Modules\Pos\Models\PosPaymentMethod;
use Modules\Pos\Models\PosProduct;
use Modules\Pos\Models\PosProductRecipe;
use Modules\Pos\Models\PosSession;
use Modules\Pos\Models\PosTable;
use Modules\Pos\Services\PosOrderSplitter;
use Modules\Pos\Services\PosReceiptImageRenderer;
use Tests\TestCase;

/**
 * Splitting a POS order: a chosen quantity of selected lines moves off a
 * source order onto a new (or merged-into) order. Covers the draft path
 * (dine-in bill split), the paid path (no re-consume / payment realloc),
 * the guards, and the two entry points (modal + Orders list).
 */
final class PosOrderSplitTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('pos');
    }

    private function splitter(): PosOrderSplitter
    {
        return app(PosOrderSplitter::class);
    }

    private function openSession(): PosSession
    {
        return PosSession::query()->create([
            'reference' => 'POS-S/0001',
            'state' => SessionState::Opened,
            'opening_cash' => 50.0,
            'opened_at' => now(),
        ]);
    }

    private function table(string $name = '1'): PosTable
    {
        $floor = PosFloor::query()->firstOrCreate(['name' => 'Main floor'], ['sequence' => 10]);

        return PosTable::query()->create([
            'pos_floor_id' => $floor->id,
            'name' => $name,
            'seats' => 4,
            'shape' => 'square',
        ]);
    }

    private function product(string $name, float $price, ?int $stock = null): PosProduct
    {
        $attrs = ['name' => $name, 'price' => $price, 'tax_rate' => 0.0, 'active' => true];
        if ($stock !== null) {
            $attrs['stock_on_hand'] = $stock;
        }

        return PosProduct::query()->create($attrs);
    }

    /**
     * @param  list<array{0: PosProduct, 1: int}>  $items
     */
    private function draft(PosSession $session, array $items, ?int $tableId = null, int $seq = 1): PosOrder
    {
        $order = PosOrder::query()->create([
            'pos_session_id' => $session->id,
            'pos_table_id' => $tableId,
            'reference' => sprintf('POS/%d/%04d', $session->id, $seq),
            'state' => OrderState::Draft,
        ]);

        foreach ($items as [$product, $qty]) {
            $line = $order->lines()->make([
                'pos_product_id' => $product->id,
                'name' => $product->name,
                'qty' => $qty,
                'unit_price' => $product->price,
                'discount' => 0,
                'tax_rate' => $product->tax_rate,
            ]);
            $line->recompute();
            $line->save();
        }

        $order->recalculate();

        return $order->refresh();
    }

    public function test_splitting_a_draft_moves_selected_units_to_a_new_draft(): void
    {
        $session = $this->openSession();
        $dest = $this->table();
        $a = $this->product('Espresso', 2.0);
        $b = $this->product('Banana Juice', 3.0);
        $order = $this->draft($session, [[$a, 1], [$b, 1]]);

        $lineB = $order->lines()->where('pos_product_id', $b->id)->firstOrFail();

        $new = $this->splitter()->split($order, [$lineB->id => 1], $dest->id, 'for guest 2');

        $this->assertSame(OrderState::Draft, $new->state);
        $this->assertSame($dest->id, $new->pos_table_id);
        $this->assertSame('for guest 2', $new->notes);
        $this->assertSame(1, $new->lines()->count());
        $this->assertEqualsWithDelta(3.0, $new->total, 0.001);

        $order->refresh();
        $this->assertSame(1, $order->lines()->count());
        $this->assertEqualsWithDelta(2.0, $order->total, 0.001);
    }

    public function test_partial_quantity_split_shrinks_the_source_line(): void
    {
        $session = $this->openSession();
        $dest = $this->table();
        $a = $this->product('Espresso', 2.0);
        $order = $this->draft($session, [[$a, 3]]);
        $line = $order->lines()->firstOrFail();

        $new = $this->splitter()->split($order, [$line->id => 2], $dest->id, null);

        $this->assertEqualsWithDelta(2.0, (float) $new->lines()->firstOrFail()->qty, 0.001);
        $this->assertEqualsWithDelta(4.0, $new->total, 0.001);

        $order->refresh();
        $this->assertEqualsWithDelta(1.0, (float) $order->lines()->firstOrFail()->qty, 0.001);
        $this->assertEqualsWithDelta(2.0, $order->total, 0.001);
    }

    public function test_split_into_an_existing_table_draft_merges(): void
    {
        $session = $this->openSession();
        $t1 = $this->table('1');
        $t2 = $this->table('2');
        $a = $this->product('Espresso', 2.0);
        $b = $this->product('Banana Juice', 3.0);

        $source = $this->draft($session, [[$a, 2]], $t1->id, 1);
        $existing = $this->draft($session, [[$b, 1]], $t2->id, 2);

        $sourceLine = $source->lines()->firstOrFail();
        $new = $this->splitter()->split($source, [$sourceLine->id => 1], $t2->id, null);

        // Merged into table 2's open draft rather than spawning a third.
        $this->assertSame($existing->id, $new->id);
        $this->assertSame(2, $new->lines()->count());
    }

    public function test_cannot_split_in_a_way_that_empties_the_original(): void
    {
        $session = $this->openSession();
        $dest = $this->table();
        $a = $this->product('Espresso', 2.0);
        $order = $this->draft($session, [[$a, 2]]);
        $line = $order->lines()->firstOrFail();

        $this->expectException(PosOrderSplitException::class);
        $this->splitter()->split($order, [$line->id => 2], $dest->id, null);
    }

    public function test_cannot_split_with_an_empty_selection(): void
    {
        $session = $this->openSession();
        $dest = $this->table();
        $a = $this->product('Espresso', 2.0);
        $order = $this->draft($session, [[$a, 2]]);

        $this->expectException(PosOrderSplitException::class);
        $this->splitter()->split($order, [], $dest->id, null);
    }

    public function test_splitting_a_paid_order_keeps_stock_and_reapportions_payment(): void
    {
        $session = $this->openSession();
        $beans = $this->product('Beans', 0.0, stock: 100);
        $coffee = $this->product('Coffee', 2.0);
        PosProductRecipe::query()->create([
            'parent_product_id' => $coffee->id,
            'component_product_id' => $beans->id,
            'quantity_consumed' => 1.0,
        ]);
        $cash = PosPaymentMethod::query()->create(['name' => 'Cash', 'is_cash' => true, 'sequence' => 1, 'active' => true]);

        $order = $this->draft($session, [[$coffee, 2]]);
        $order->registerPayment($cash, 4.0);
        $order->finalizeSale();
        $order->refresh();

        // Two coffees consumed two beans on finalisation.
        $this->assertEqualsWithDelta(98.0, (float) $beans->fresh()?->stock_on_hand, 0.001);

        $line = $order->lines()->firstOrFail();
        $new = $this->splitter()->split($order, [$line->id => 1], null, null);

        // Splitting a paid order must NOT decrement stock a second time.
        $this->assertEqualsWithDelta(98.0, (float) $beans->fresh()?->stock_on_hand, 0.001);

        $this->assertSame(OrderState::Done, $new->state);
        $this->assertTrue((bool) $new->components_consumed);
        $this->assertEqualsWithDelta(2.0, $new->total, 0.001);
        $this->assertEqualsWithDelta(2.0, $new->paid_total, 0.001);

        $order->refresh();
        $this->assertEqualsWithDelta(2.0, $order->total, 0.001);
        $this->assertEqualsWithDelta(2.0, $order->paid_total, 0.001);

        // Combined tendered cash across both orders is unchanged (4.00).
        $this->assertEqualsWithDelta(4.0, $order->paymentsTotal() + $new->paymentsTotal(), 0.001);
    }

    public function test_split_modal_creates_the_order_and_dispatches_event(): void
    {
        $session = $this->openSession();
        $dest = $this->table();
        $a = $this->product('Espresso', 2.0);
        $b = $this->product('Banana Juice', 3.0);
        $order = $this->draft($session, [[$a, 1], [$b, 1]]);
        $lineB = $order->lines()->where('pos_product_id', $b->id)->firstOrFail();

        Livewire::test(SplitOrderModal::class)
            ->call('openFor', $order->id)
            ->set('destTable', (string) $dest->id)
            ->set('move', [$lineB->id => 1])
            ->call('submit')
            ->assertDispatched('order-split')
            ->assertSet('open', false);

        $this->assertSame(2, PosOrder::query()->count());
    }

    public function test_orders_list_renders_and_cancels_a_draft(): void
    {
        $session = $this->openSession();
        $a = $this->product('Espresso', 2.0);
        $order = $this->draft($session, [[$a, 1]]);

        Livewire::test(PosOrders::class)
            ->assertOk()
            ->assertSee($order->reference)
            ->call('cancelOrder', $order->id);

        $this->assertSame(OrderState::Cancelled, $order->fresh()?->state);
    }

    public function test_receipt_print_renders_for_a_paid_order(): void
    {
        $session = $this->openSession();
        $cash = PosPaymentMethod::query()->create(['name' => 'Cash', 'is_cash' => true, 'sequence' => 1, 'active' => true]);
        $coffee = $this->product('Coffee', 2.0);
        $order = $this->draft($session, [[$coffee, 1]]);
        $order->registerPayment($cash, 2.0);
        $order->finalizeSale();

        // Invoke the controller directly — module routes only register on the
        // boot AFTER install, so an HTTP GET would 404 in the test harness.
        $controller = new PosReceiptPrintController();
        $view = $controller(app(PosReceiptImageRenderer::class), (int) $order->id);

        $this->assertStringContainsString($order->reference, $view->render());
    }
}
