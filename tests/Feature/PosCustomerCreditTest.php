<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Pos\Enums\OrderState;
use Modules\Pos\Enums\SessionState;
use Modules\Pos\Livewire\PosTerminal;
use Modules\Pos\Models\PosCustomerDiscount;
use Modules\Pos\Models\PosOrder;
use Modules\Pos\Models\PosProduct;
use Modules\Pos\Models\PosSession;
use Tests\TestCase;

/**
 * Prepaid credit ("balance") on a per-phone customer discount: each order draws
 * the wallet down at FULL price until it hits 0, and only then does the discount
 * % apply to what the customer actually pays.
 */
final class PosCustomerCreditTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('pos');
    }

    private function openSession(): PosSession
    {
        return PosSession::query()->create([
            'reference' => 'POS-S/0001', 'state' => SessionState::Opened,
            'opening_cash' => 0.0, 'opened_at' => now(),
        ]);
    }

    private function discount(float $percent, float $balance, string $phone = '33445566'): PosCustomerDiscount
    {
        return PosCustomerDiscount::query()->create([
            'phone' => $phone, 'discount_percent' => $percent,
            'prepaid_balance' => $balance, 'active' => true,
        ]);
    }

    /** An order with a single goods line of the given unit price × qty. */
    private function orderWithGoods(PosSession $session, float $unitPrice, int $qty = 1): PosOrder
    {
        $order = PosOrder::query()->create([
            'reference' => 'POS/0001', 'pos_session_id' => $session->id, 'state' => OrderState::Draft,
        ]);

        $line = $order->lines()->create([
            'name' => 'Item', 'qty' => $qty, 'unit_price' => $unitPrice, 'discount' => 0, 'tax_rate' => 0,
        ]);
        $line->recompute();
        $line->save();
        $order->recalculate();

        return $order->fresh() ?? $order;
    }

    public function test_credit_covers_the_whole_bill_and_customer_pays_nothing(): void
    {
        $session = $this->openSession();
        $this->discount(percent: 50, balance: 30.0);
        $order = $this->orderWithGoods($session, 8.0);   // gross 8, credit 30

        $order->applyCustomerDiscount('33445566');

        $this->assertSame(8.0, round((float) $order->credit_applied, 2));   // full price drawn
        $this->assertSame(0.0, round((float) $order->customer_discount_total, 2)); // no % yet
        $this->assertSame(0.0, round((float) $order->total, 2));            // pays nothing
    }

    public function test_finalising_draws_the_wallet_down_once(): void
    {
        $session = $this->openSession();
        $discount = $this->discount(percent: 50, balance: 30.0);
        $order = $this->orderWithGoods($session, 8.0);

        $order->applyCustomerDiscount('33445566');
        $order->finalizeSale();   // total 0 — no tendered cash needed

        $this->assertSame(OrderState::Done, $order->fresh()?->state);
        $this->assertTrue((bool) $order->fresh()?->credit_consumed);
        $this->assertSame(22.0, round((float) $discount->fresh()?->prepaid_balance, 2)); // 30 − 8

        // Idempotent: re-running recalculate can't re-draw or change the amount.
        $order->refresh();
        $order->recalculate();
        $this->assertSame(22.0, round((float) $discount->fresh()?->prepaid_balance, 2));
        $this->assertSame(8.0, round((float) $order->fresh()?->credit_applied, 2));
    }

    public function test_a_bill_larger_than_the_balance_splits_credit_then_discount(): void
    {
        $session = $this->openSession();
        $discount = $this->discount(percent: 50, balance: 10.0);
        $order = $this->orderWithGoods($session, 30.0);   // gross 30, credit 10

        $order->applyCustomerDiscount('33445566');

        // 10 covered by credit at full price; remaining 20 gets 50% off → pay 10.
        $this->assertSame(10.0, round((float) $order->credit_applied, 2));
        $this->assertSame(10.0, round((float) $order->customer_discount_total, 2)); // 50% of 20
        $this->assertSame(10.0, round((float) $order->total, 2));

        // Pay the 10 and finalise → wallet emptied.
        $method = \Modules\Pos\Models\PosPaymentMethod::query()->create(['name' => 'Cash', 'is_cash' => true, 'active' => true, 'sequence' => 10]);
        $order->registerPayment($method, 10.0);
        $order->finalizeSale();

        $this->assertSame(0.0, round((float) $discount->fresh()?->prepaid_balance, 2));
    }

    public function test_once_the_balance_is_zero_the_discount_applies(): void
    {
        $session = $this->openSession();
        $this->discount(percent: 50, balance: 0.0);
        $order = $this->orderWithGoods($session, 10.0);

        $order->applyCustomerDiscount('33445566');

        $this->assertSame(0.0, round((float) $order->credit_applied, 2));   // no credit
        $this->assertSame(5.0, round((float) $order->customer_discount_total, 2)); // 50% off
        $this->assertSame(5.0, round((float) $order->total, 2));
    }

    public function test_a_fully_credited_zero_total_order_can_be_closed_at_the_register(): void
    {
        $session = $this->openSession();
        $discount = $this->discount(percent: 50, balance: 30.0);
        $product = PosProduct::query()->create(['name' => 'Tea', 'price' => 5.0, 'tax_rate' => 0.0, 'active' => true]);

        Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->call('addProduct', $product->id)
            ->set('localPhone', '33445566')     // triggers the discount match
            ->call('startPayment')
            ->call('validateOrder');

        $order = PosOrder::query()->latest('id')->first();
        $this->assertSame(OrderState::Done, $order?->state);
        $this->assertSame(0.0, round((float) $order?->total, 2));
        $this->assertSame(5.0, round((float) $order?->credit_applied, 2));
        $this->assertSame(25.0, round((float) $discount->fresh()?->prepaid_balance, 2)); // 30 − 5
    }

    public function test_the_balance_never_goes_negative(): void
    {
        $discount = $this->discount(percent: 0, balance: -50.0);   // clamped on save

        $this->assertSame(0.0, round((float) $discount->fresh()?->prepaid_balance, 2));
    }
}
