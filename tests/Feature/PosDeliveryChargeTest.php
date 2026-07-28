<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Business\Feature;
use App\Erp\Business\Features;
use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Pos\Enums\SessionState;
use Modules\Pos\Livewire\PosTerminal;
use Modules\Pos\Models\PosOrder;
use Modules\Pos\Models\PosProduct;
use Modules\Pos\Models\PosSession;
use Tests\TestCase;

/**
 * A delivery order carries two different amounts:
 *   · delivery_fee    — the cost WE absorb (our expense, never on the bill);
 *   · delivery_charge — what the CUSTOMER pays for delivery (urgent request, or
 *     an offer with no free delivery) — added to the bill as revenue.
 * Both can appear on one order (we pay the base, the customer pays the extra).
 */
final class PosDeliveryChargeTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('pos');
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

    public function test_delivery_charged_to_the_customer_is_added_to_the_total_while_our_cost_is_not(): void
    {
        $session = $this->openSession();
        $product = PosProduct::query()->create(['name' => 'Perfume', 'price' => 10, 'tax_rate' => 0, 'active' => true, 'stock_on_hand' => 5]);

        Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->call('setChannel', 'remote')
            ->call('addProduct', $product->id)
            ->set('deliveryFee', '1.1')      // our cost — expense, NOT on the bill
            ->set('deliveryCharge', '1.5')   // urgent extra — customer pays it
            ->set('customerName', 'Ali')
            ->set('localPhone', '33123456');

        $order = PosOrder::query()->where('pos_session_id', $session->id)->firstOrFail();

        // Goods 10 + delivery the customer pays 1.5 = 11.5. Our 1.1 cost is NOT
        // added to what the customer owes.
        $this->assertSame(11.5, (float) $order->total);
        $this->assertSame(1.5, (float) $order->delivery_charge);
        $this->assertSame(1.1, (float) $order->delivery_fee);
    }

    public function test_an_order_with_no_customer_charge_bills_the_goods_only(): void
    {
        $session = $this->openSession();
        $product = PosProduct::query()->create(['name' => 'Perfume', 'price' => 20, 'tax_rate' => 0, 'active' => true, 'stock_on_hand' => 5]);

        Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->call('setChannel', 'remote')
            ->call('addProduct', $product->id)
            ->set('deliveryFee', '1.1');     // on us — customer charge left empty

        $order = PosOrder::query()->where('pos_session_id', $session->id)->firstOrFail();

        $this->assertSame(20.0, (float) $order->total);
        $this->assertSame(0.0, (float) $order->delivery_charge);
    }

    public function test_switching_back_to_shop_drops_the_customer_delivery_charge(): void
    {
        $session = $this->openSession();
        $product = PosProduct::query()->create(['name' => 'Perfume', 'price' => 10, 'tax_rate' => 0, 'active' => true, 'stock_on_hand' => 5]);

        Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->call('setChannel', 'remote')
            ->call('addProduct', $product->id)
            ->set('deliveryCharge', '2')
            ->call('setChannel', 'shop');

        $order = PosOrder::query()->where('pos_session_id', $session->id)->firstOrFail();

        $this->assertSame(0.0, (float) $order->delivery_charge);
        $this->assertSame(10.0, (float) $order->total);
    }
}
