<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Business\Feature;
use App\Erp\Business\Features;
use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Providers\AccountingServiceProvider;
use Modules\Pos\Enums\FulfillmentStatus;
use Modules\Pos\Enums\OrderState;
use Modules\Pos\Enums\SalesChannel;
use Modules\Pos\Enums\SessionState;
use Modules\Pos\Events\PosOrderPaid;
use Modules\Pos\Livewire\PosTerminal;
use Modules\Pos\Livewire\RemoteOrders;
use Modules\Pos\Models\PosOrder;
use Modules\Pos\Models\PosPaymentMethod;
use Modules\Pos\Models\PosProduct;
use Modules\Pos\Models\PosSession;
use Tests\TestCase;

/**
 * Remote / delivery sales: an order rung up on the same register but tagged as
 * a remote channel — captures the customer + delivery address, adds a delivery
 * fee to the total, and works through a fulfillment queue on its own dashboard.
 * Same shop stock. Feature-gated to Remote sales.
 */
final class PosRemoteSalesTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('pos');
    }

    private function enableRemote(): void
    {
        Features::setOverrides([Feature::RemoteSales->value => true]);
    }

    private function openSession(): PosSession
    {
        return PosSession::query()->create([
            'reference' => 'POS-S/0001',
            'state' => SessionState::Opened,
            'opening_cash' => 0.0,
            'opened_at' => now(),
        ]);
    }

    public function test_a_remote_sale_keeps_delivery_off_the_customer_total(): void
    {
        $this->enableRemote();
        $session = $this->openSession();
        PosPaymentMethod::query()->create(['name' => 'Cash on delivery', 'is_cash' => false, 'sequence' => 10]);
        $product = PosProduct::query()->create(['name' => 'Perfume', 'price' => 10, 'tax_rate' => 0, 'active' => true, 'stock_on_hand' => 5]);

        Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->call('setChannel', 'remote')
            ->call('addProduct', $product->id)
            ->set('customerName', 'Ali Hasan')
            ->set('localPhone', '33123456')
            ->set('deliveryAddress', 'Block 338, Road 1, Manama')
            ->set('deliveryFee', '2')
            ->call('startPayment')
            ->call('addPayment')
            ->call('validateOrder');

        $order = PosOrder::query()->where('pos_session_id', $session->id)->firstOrFail();
        $this->assertSame(SalesChannel::Remote, $order->channel);
        // The customer pays for the goods only — the delivery fee is OUR cost.
        $this->assertSame(10.0, (float) $order->total);
        $this->assertSame(2.0, (float) $order->delivery_fee);
        $this->assertSame('Ali Hasan', $order->customer_name);
        $this->assertSame('Block 338, Road 1, Manama', $order->delivery_address);
        $this->assertSame('97333123456', $order->customer_phone);
        $this->assertSame(FulfillmentStatus::New, $order->fulfillment_status);
        $this->assertSame(OrderState::Done, $order->state);
    }

    public function test_the_delivery_cost_is_booked_as_an_operating_expense(): void
    {
        $this->enableRemote();
        app(ModuleManager::class)->install('accounting');
        // Modules boot before setUp installs them, so re-register the provider
        // to wire the PosOrderPaid listeners (the known module-boot gap).
        $this->app->register(AccountingServiceProvider::class);
        (new ChartOfAccountsSeeder())->run();

        $session = $this->openSession();
        PosPaymentMethod::query()->create(['name' => 'Cash', 'is_cash' => true, 'sequence' => 1]);
        $order = PosOrder::query()->create([
            'reference' => 'POS/1/0009',
            'pos_session_id' => $session->id,
            'state' => OrderState::Done,
            'channel' => SalesChannel::Remote->value,
            'fulfillment_status' => FulfillmentStatus::New->value,
            'customer_name' => 'Ali',
            'total' => 10,
            'delivery_fee' => 2.8,
            'paid_total' => 0,
            'ordered_at' => now(),
        ]);

        // Collecting the money fires PosOrderPaid → sale + delivery-cost entries.
        Livewire::test(RemoteOrders::class)->call('collectPayment', $order->id);

        $entry = JournalEntry::query()->where('reference', 'DEL/POS/1/0009')->first();
        $this->assertNotNull($entry);

        $opex = Account::byCode('5030');    // operating expenses
        $cash = Account::byCode('1010');    // cash on hand
        $transit = Account::byCode('1150'); // money in transit (delivery)
        $this->assertNotNull($opex);
        $this->assertNotNull($cash);
        $this->assertNotNull($transit);

        // Dr Operating Expenses 2.8 — the delivery is still our cost.
        $this->assertSame(2.8, (float) $entry->items()->where('account_id', $opex->id)->sum('debit'));
        // Cr Money in Transit, NOT cash: on a delivery sale the company keeps
        // its fee out of what it collected, so nothing leaves our drawer — the
        // fee just reduces what they still owe us.
        $this->assertSame(2.8, (float) $entry->items()->where('account_id', $transit->id)->sum('credit'));
        $this->assertSame(0.0, (float) $entry->items()->where('account_id', $cash->id)->sum('credit'));
    }

    public function test_a_remote_order_cannot_be_paid_without_a_customer_name_and_phone(): void
    {
        $this->enableRemote();
        $session = $this->openSession();
        PosPaymentMethod::query()->create(['name' => 'Cash', 'is_cash' => true, 'sequence' => 10]);
        $product = PosProduct::query()->create(['name' => 'Perfume', 'price' => 10, 'tax_rate' => 0, 'active' => true]);

        $c = Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->call('setChannel', 'remote')
            ->call('addProduct', $product->id)
            ->call('startPayment');

        // Blocked — the payment overlay never opens.
        $c->assertSet('paying', false);
        $this->assertNotSame('', $c->get('channelError'));

        // With the customer named + a phone, payment proceeds.
        $c->set('customerName', 'Ali')
            ->set('localPhone', '33123456')
            ->call('startPayment')
            ->assertSet('paying', true);
    }

    public function test_switching_back_to_shop_drops_the_delivery_fee(): void
    {
        $this->enableRemote();
        $session = $this->openSession();
        $product = PosProduct::query()->create(['name' => 'Perfume', 'price' => 10, 'tax_rate' => 0, 'active' => true]);

        Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->call('setChannel', 'remote')
            ->call('addProduct', $product->id)
            ->set('deliveryFee', '3')
            ->call('setChannel', 'shop');

        $order = PosOrder::query()->where('pos_session_id', $session->id)->firstOrFail();
        $this->assertSame(SalesChannel::Shop, $order->channel);
        $this->assertSame(0.0, (float) $order->delivery_fee);
        $this->assertSame(10.0, (float) $order->total);
    }

    public function test_advance_fulfillment_walks_the_pipeline_and_stops_at_delivered(): void
    {
        $session = $this->openSession();
        $order = PosOrder::query()->create([
            'reference' => 'POS/1/0001',
            'pos_session_id' => $session->id,
            'state' => OrderState::Done,
            'channel' => SalesChannel::Remote->value,
            'fulfillment_status' => FulfillmentStatus::New->value,
            'total' => 10,
        ]);

        $order->advanceFulfillment();
        $this->assertSame(FulfillmentStatus::Packed, $order->fresh()?->fulfillment_status);
        $order->fresh()?->advanceFulfillment();
        $this->assertSame(FulfillmentStatus::OutForDelivery, $order->fresh()?->fulfillment_status);
        $order->fresh()?->advanceFulfillment();
        $this->assertSame(FulfillmentStatus::Delivered, $order->fresh()?->fulfillment_status);
        // Already delivered — no further step.
        $order->fresh()?->advanceFulfillment();
        $this->assertSame(FulfillmentStatus::Delivered, $order->fresh()?->fulfillment_status);
    }

    public function test_the_remote_dashboard_advances_an_order(): void
    {
        $this->enableRemote();
        $session = $this->openSession();
        $order = PosOrder::query()->create([
            'reference' => 'POS/1/0002',
            'pos_session_id' => $session->id,
            'state' => OrderState::Done,
            'channel' => SalesChannel::Remote->value,
            'fulfillment_status' => FulfillmentStatus::New->value,
            'customer_name' => 'Sara',
            'total' => 15,
            'ordered_at' => now(),
        ]);

        Livewire::test(RemoteOrders::class)
            ->assertSee('Sara')
            ->call('advance', $order->id);

        $this->assertSame(FulfillmentStatus::Packed, $order->fresh()?->fulfillment_status);
    }

    public function test_a_cod_order_is_confirmed_unpaid_without_booking_the_sale(): void
    {
        $this->enableRemote();
        Event::fake([PosOrderPaid::class]);
        $session = $this->openSession();
        $product = PosProduct::query()->create(['name' => 'Perfume', 'price' => 10, 'tax_rate' => 0, 'active' => true, 'stock_on_hand' => 5]);

        Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->call('setChannel', 'remote')
            ->call('addProduct', $product->id)
            ->set('customerName', 'Ali')
            ->set('localPhone', '33123456')
            ->set('deliveryFee', '1')
            ->call('confirmCod');

        $order = PosOrder::query()->where('pos_session_id', $session->id)->firstOrFail();
        $this->assertSame(OrderState::Done, $order->state);          // committed to the queue
        $this->assertSame(FulfillmentStatus::New, $order->fulfillment_status);
        $this->assertSame('unpaid', $order->paymentBadge());          // but not paid
        $this->assertSame(0.0, (float) $order->paid_total);
        $this->assertSame(10.0, (float) $order->total);               // goods only; delivery is our cost
        $this->assertSame(1.0, (float) $order->delivery_fee);
        // No cash is booked until the money is collected.
        Event::assertNotDispatched(PosOrderPaid::class);
    }

    public function test_collecting_payment_marks_a_cod_order_paid_and_books_the_sale(): void
    {
        $this->enableRemote();
        $session = $this->openSession();
        PosPaymentMethod::query()->create(['name' => 'Cash', 'is_cash' => true, 'sequence' => 1]);
        $order = PosOrder::query()->create([
            'reference' => 'POS/1/0003',
            'pos_session_id' => $session->id,
            'state' => OrderState::Done,
            'channel' => SalesChannel::Remote->value,
            'fulfillment_status' => FulfillmentStatus::New->value,
            'customer_name' => 'Sara',
            'total' => 15,
            'paid_total' => 0,
            'ordered_at' => now(),
        ]);

        Event::fake([PosOrderPaid::class]);
        Livewire::test(RemoteOrders::class)->call('collectPayment', $order->id);

        $order->refresh();
        $this->assertTrue($order->isPaid());
        $this->assertSame(15.0, (float) $order->paid_total);
        $this->assertSame('paid', $order->paymentBadge());
        // Now the sale posts to the journal / receipt goes out.
        Event::assertDispatched(PosOrderPaid::class);
    }

    public function test_the_terminal_preselects_remote_from_the_query_param(): void
    {
        $this->enableRemote();
        $session = $this->openSession();

        // The dashboard's "New remote order" button links here with ?channel=remote.
        Livewire::withQueryParams(['channel' => 'remote'])
            ->test(PosTerminal::class, ['session' => $session->id])
            ->assertSet('channel', 'remote');
    }

    public function test_a_delivery_reference_is_captured_at_the_register(): void
    {
        $this->enableRemote();
        $session = $this->openSession();
        $product = PosProduct::query()->create(['name' => 'Perfume', 'price' => 10, 'tax_rate' => 0, 'active' => true]);

        Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->call('setChannel', 'remote')
            ->call('addProduct', $product->id)
            ->set('deliveryReference', 'ARMEX-99231');

        $order = PosOrder::query()->where('pos_session_id', $session->id)->firstOrFail();
        $this->assertSame('ARMEX-99231', $order->delivery_reference);
    }

    public function test_the_dashboard_can_set_a_delivery_reference(): void
    {
        $this->enableRemote();
        $session = $this->openSession();
        $order = PosOrder::query()->create([
            'reference' => 'POS/1/0007',
            'pos_session_id' => $session->id,
            'state' => OrderState::Done,
            'channel' => SalesChannel::Remote->value,
            'fulfillment_status' => FulfillmentStatus::New->value,
            'customer_name' => 'Noor',
            'total' => 12,
            'ordered_at' => now(),
        ]);

        Livewire::test(RemoteOrders::class)
            ->call('setDeliveryReference', $order->id, 'DHL-5567');

        $this->assertSame('DHL-5567', $order->fresh()?->delivery_reference);
    }

    public function test_the_channel_toggle_and_dashboard_are_gated_to_the_feature(): void
    {
        $session = $this->openSession();

        // Off → the terminal shows no channel toggle, and the dashboard sends
        // the user to the POS home instead of a jarring 404.
        Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->assertDontSee('Remote / delivery');
        Livewire::test(RemoteOrders::class)->assertRedirect(url('/app/pos'));

        // On → the toggle appears.
        $this->enableRemote();
        Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->assertSee('Remote / delivery');
    }
}
