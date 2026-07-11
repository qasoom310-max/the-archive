<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Business\Feature;
use App\Erp\Business\Features;
use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Pos\Enums\FulfillmentStatus;
use Modules\Pos\Enums\OrderState;
use Modules\Pos\Enums\SalesChannel;
use Modules\Pos\Enums\SessionState;
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

    public function test_a_remote_sale_adds_the_delivery_fee_and_enters_the_queue(): void
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
        $this->assertSame(12.0, (float) $order->total);            // 10 goods + 2 delivery
        $this->assertSame(2.0, (float) $order->delivery_fee);
        $this->assertSame('Ali Hasan', $order->customer_name);
        $this->assertSame('Block 338, Road 1, Manama', $order->delivery_address);
        $this->assertSame('97333123456', $order->customer_phone);
        $this->assertSame(FulfillmentStatus::New, $order->fulfillment_status);
        $this->assertSame(OrderState::Done, $order->state);
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

    public function test_the_channel_toggle_and_dashboard_are_gated_to_the_feature(): void
    {
        $session = $this->openSession();

        // Off → the terminal shows no channel toggle, and the dashboard 404s.
        Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->assertDontSee('Remote / delivery');
        $this->get('/app/pos/remote')->assertNotFound();

        // On → the toggle appears.
        $this->enableRemote();
        Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->assertSee('Remote / delivery');
    }
}
