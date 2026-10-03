<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Pos\Enums\OrderState;
use Modules\Pos\Enums\SalesChannel;
use Modules\Pos\Enums\SessionState;
use Modules\Pos\Exceptions\PosOrderSplitException;
use Modules\Pos\Http\Controllers\PosReceiptPrintController;
use Modules\Pos\Livewire\PosOrders;
use Modules\Pos\Livewire\SplitOrderModal;
use Modules\Pos\Models\PosCustomerDiscount;
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

    public function test_turning_split_order_off_hides_and_refuses_it(): void
    {
        $session = $this->openSession();
        $a = $this->product('Espresso', 2.0);
        $order = $this->draft($session, [[$a, 2]]);

        // On by default: the row offers the split action.
        Livewire::test(PosOrders::class)
            ->assertOk()
            ->assertViewHas('rows', fn (array $rows): bool => (bool) $rows[0]['splittable']);

        \App\Erp\Business\Features::setOverrides([\App\Erp\Business\Feature::SplitOrder->value => false]);

        // Off: the action is gone from the list…
        Livewire::test(PosOrders::class)
            ->assertOk()
            ->assertViewHas('rows', fn (array $rows): bool => ! $rows[0]['splittable']);

        // …the modal refuses to open…
        Livewire::test(SplitOrderModal::class)
            ->call('openFor', $order->id)
            ->assertSet('open', false);

        // …and a crafted submit is refused outright.
        Livewire::test(PosOrders::class)
            ->call('openSplit', $order->id)
            ->assertNotFound();
    }

    public function test_marking_a_paid_order_delivered_records_our_cost_without_touching_the_total(): void
    {
        $session = $this->openSession();
        $cash = PosPaymentMethod::query()->create(['name' => 'Cash', 'is_cash' => true, 'sequence' => 1, 'active' => true]);
        $coffee = $this->product('Coffee', 20.0);
        $order = $this->draft($session, [[$coffee, 1]]);
        $order->registerPayment($cash, 20.0);
        $order->finalizeSale();
        $order->refresh();

        Livewire::test(PosOrders::class)
            ->call('openDelivery', $order->id)
            ->set('deliveryFee', '1.5')
            ->call('saveDelivery')
            ->assertHasNoErrors()
            ->assertSet('deliveryOrderId', null);

        $fresh = $order->fresh();
        $this->assertSame(SalesChannel::Remote, $fresh?->channel);
        $this->assertEqualsWithDelta(1.5, (float) $fresh?->delivery_fee, 0.001);
        // The customer's bill is untouched — the fee is OUR cost, so an already
        // settled order stays balanced.
        $this->assertEqualsWithDelta(20.0, (float) $fresh?->total, 0.001);
        $this->assertEqualsWithDelta(20.0, (float) $fresh?->paid_total, 0.001);
    }

    public function test_a_mis_tagged_delivery_can_be_cleared(): void
    {
        $session = $this->openSession();
        $coffee = $this->product('Coffee', 20.0);
        $order = $this->draft($session, [[$coffee, 1]]);

        $component = Livewire::test(PosOrders::class)
            ->call('openDelivery', $order->id)
            ->set('deliveryFee', '2')
            ->call('saveDelivery');
        $this->assertSame(SalesChannel::Remote, $order->fresh()?->channel);

        $component->call('openDelivery', $order->id)->call('clearDelivery');

        $fresh = $order->fresh();
        $this->assertSame(SalesChannel::Shop, $fresh?->channel);
        $this->assertEqualsWithDelta(0.0, (float) $fresh?->delivery_fee, 0.001);
    }

    public function test_marking_an_order_delivered_is_admin_only(): void
    {
        $session = $this->openSession();
        $coffee = $this->product('Coffee', 20.0);
        $order = $this->draft($session, [[$coffee, 1]]);

        \App\Models\Auth\ModelAccess::query()->create([
            'name' => 'pos.order all',
            'model' => 'pos.order',
            'group_id' => null,
            'perm_read' => true,
            'perm_write' => true,
            'perm_create' => true,
            'perm_unlink' => false,
        ]);
        $this->actingAs(User::factory()->create(['is_admin' => false]));

        Livewire::test(PosOrders::class)
            ->assertOk()
            ->call('openDelivery', $order->id)
            ->assertForbidden();
    }

    public function test_changing_one_orders_date_leaves_the_others_alone(): void
    {
        // Entering a paper log after the fact: each order must land on its own
        // day, so the manager can still see what sold on each date.
        $session = $this->openSession();
        $a = $this->product('Espresso', 2.0);

        $first = $this->draft($session, [[$a, 1]], seq: 1);
        $second = $this->draft($session, [[$a, 1]], seq: 2);
        $first->ordered_at = now()->setTime(14, 30);
        $first->saveQuietly();
        $second->ordered_at = now()->setTime(16, 0);
        $second->saveQuietly();
        $secondWas = $second->fresh()?->ordered_at?->toDateTimeString();

        Livewire::test(PosOrders::class)
            ->call('openDate', $first->id)
            ->set('orderDate', '2026-08-08')
            ->call('saveDate')
            ->assertHasNoErrors()
            ->assertSet('dateOrderId', null);

        // Only the picked order moved, and it kept its time of day.
        $moved = $first->fresh();
        $this->assertSame('2026-08-08', $moved?->ordered_at?->toDateString());
        $this->assertSame('14:30', $moved?->ordered_at?->format('H:i'));

        // The sibling is untouched — this is the whole point.
        $this->assertSame($secondWas, $second->fresh()?->ordered_at?->toDateTimeString());
    }

    public function test_changing_an_order_date_is_admin_only(): void
    {
        $session = $this->openSession();
        $a = $this->product('Espresso', 2.0);
        $order = $this->draft($session, [[$a, 1]]);

        // A cashier: may read AND write orders, but is not an admin — so they
        // can open the list yet must not be able to re-date a sale.
        \App\Models\Auth\ModelAccess::query()->create([
            'name' => 'pos.order all',
            'model' => 'pos.order',
            'group_id' => null,
            'perm_read' => true,
            'perm_write' => true,
            'perm_create' => true,
            'perm_unlink' => false,
        ]);
        $this->actingAs(User::factory()->create(['is_admin' => false]));

        Livewire::test(PosOrders::class)
            ->assertOk()
            ->call('openDate', $order->id)
            ->assertForbidden();
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

    /** The browser slip needs the logo's web address, not its file path on the server. */
    public function test_the_printed_receipt_loads_the_logo_by_its_url(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        \Illuminate\Support\Facades\Storage::disk('public')->put('company/logo.png', 'png');
        \App\Erp\Settings\Setting::set('company.logo', 'company/logo.png');

        $session = $this->openSession();
        $cash = PosPaymentMethod::query()->create(['name' => 'Cash', 'is_cash' => true, 'sequence' => 1, 'active' => true]);
        $order = $this->draft($session, [[$this->product('Coffee', 2.0), 1]]);
        $order->registerPayment($cash, 2.0);
        $order->finalizeSale();

        $html = (new PosReceiptPrintController())(app(PosReceiptImageRenderer::class), (int) $order->id)->render();

        $url = (string) \Illuminate\Support\Facades\Storage::disk('public')->url('company/logo.png');
        $this->assertStringContainsString('src="' . e($url) . '"', $html);
        $this->assertStringNotContainsString((string) \Illuminate\Support\Facades\Storage::disk('public')->path('company/logo.png'), $html);
    }

    public function test_splitting_an_order_paid_with_store_credit_leaves_both_settled(): void
    {
        // A wallet-covered sale (customer paid nothing) split in two. Before
        // the fix the new order carried no credit, so it re-priced at full
        // price and came out UNPAID — inventing revenue on a settled sale.
        $session = $this->openSession();
        $dest = $this->table('2');
        $a = $this->product('Espresso', 2.0);
        $b = $this->product('Banana Juice', 3.0);

        PosCustomerDiscount::query()->create([
            'phone' => '33445566', 'discount_percent' => 0.0,
            'prepaid_balance' => 30.0, 'active' => true,
        ]);

        $order = $this->draft($session, [[$a, 1], [$b, 1]]);
        $order->applyCustomerDiscount('33445566');
        $this->assertSame(0.0, round((float) $order->total, 2));

        $order->finalizeSale();
        $order->refresh();
        $this->assertSame(5.0, round((float) $order->credit_applied, 2));

        $lineB = $order->lines()->where('pos_product_id', $b->id)->firstOrFail();
        $new = $this->splitter()->split($order, [$lineB->id => 1], $dest->id, null);

        $order->refresh();

        // Each order carries the credit for the goods it actually holds …
        $this->assertSame(2.0, round((float) $order->credit_applied, 2));
        $this->assertSame(3.0, round((float) $new->credit_applied, 2));
        $this->assertSame(5.0, round((float) $order->credit_applied + (float) $new->credit_applied, 2));

        // … so both are still fully settled, and neither draws the wallet again.
        $this->assertSame(0.0, round((float) $order->total, 2));
        $this->assertSame(0.0, round((float) $new->total, 2));
        $this->assertTrue((bool) $new->credit_consumed);

        $new->consumeCustomerCredit();
        $this->assertSame(25.0, round((float) PosCustomerDiscount::query()->firstOrFail()->prepaid_balance, 2));
    }
}
