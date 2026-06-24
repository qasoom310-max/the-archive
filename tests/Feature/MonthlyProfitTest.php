<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Erp\Reports\MonthlyFinancials;
use App\Livewire\Pages\MonthlyProfit;
use App\Models\Expense;
use App\Models\ExpensePayment;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Modules\Pos\Enums\OrderState;
use Modules\Pos\Enums\SessionState;
use Modules\Pos\Models\PosIngredient;
use Modules\Pos\Models\PosOrder;
use Modules\Pos\Models\PosProduct;
use Modules\Pos\Models\PosProductRecipe;
use Modules\Pos\Models\PosSession;
use Tests\TestCase;

/**
 * Monthly P&L: Sales − COGS (recipe component cost, else the resale product's
 * own cost) − Expenses = Net Profit, plus recurring-bill tracking. Admin-only.
 */
final class MonthlyProfitTest extends TestCase
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
            'reference' => 'POS-S/0001',
            'state' => SessionState::Opened,
            'opening_cash' => 0,
            'opened_at' => now(),
        ]);
    }

    public function test_computes_sales_cogs_gross_expenses_and_net(): void
    {
        $month = Carbon::now()->format('Y-m');
        $session = $this->openSession();

        // Made product: Ginger milk @ 0.5; uses 0.25 L milk @ cost 0.4 ⇒ unit cost 0.1.
        $milk = PosIngredient::query()->create(['name' => 'Milk', 'cost_price' => 0.4, 'stock_on_hand' => 100, 'unit' => 'l']);
        $ginger = PosProduct::query()->create(['name' => 'Ginger milk', 'price' => 0.5, 'cost_price' => 0, 'tax_rate' => 0, 'active' => true]);
        PosProductRecipe::query()->create(['parent_product_id' => $ginger->id, 'component_ingredient_id' => $milk->id, 'quantity_consumed' => 0.25]);

        // Resale product: Pepsi @ 1.0, own cost 0.2.
        $pepsi = PosProduct::query()->create(['name' => 'Pepsi', 'price' => 1.0, 'cost_price' => 0.2, 'tax_rate' => 0, 'active' => true]);

        // One done order this month: 4 ginger (2.0) + 3 pepsi (3.0) = 5.0.
        $order = PosOrder::query()->create(['pos_session_id' => $session->id, 'reference' => 'POS/1', 'state' => OrderState::Done, 'total' => 5.0, 'ordered_at' => now()]);
        $order->lines()->create(['pos_product_id' => $ginger->id, 'name' => 'Ginger milk', 'qty' => 4, 'unit_price' => 0.5, 'discount' => 0, 'tax_rate' => 0]);
        $order->lines()->create(['pos_product_id' => $pepsi->id, 'name' => 'Pepsi', 'qty' => 3, 'unit_price' => 1.0, 'discount' => 0, 'tax_rate' => 0]);

        // Rent expense paid this month.
        $rent = Expense::query()->create(['name' => 'Rent', 'category' => 'rent', 'amount' => 100]);
        ExpensePayment::query()->create(['expense_id' => $rent->id, 'name' => 'Rent', 'period' => $month, 'amount' => 100, 'paid_on' => now()->toDateString()]);

        $f = app(MonthlyFinancials::class)->forMonth($month);

        $this->assertSame(5.0, $f['sales']);
        // COGS: ginger 4 × 0.1 = 0.4; pepsi 3 × 0.2 = 0.6; total 1.0.
        $this->assertSame(1.0, $f['cogs']);
        $this->assertSame(4.0, $f['gross']);      // 5 − 1
        $this->assertSame(100.0, $f['expenses']);
        $this->assertSame(-96.0, $f['net']);      // 4 − 100
    }

    public function test_marking_a_bill_paid_records_the_actual_amount(): void
    {
        $month = Carbon::now()->format('Y-m');
        $rent = Expense::query()->create(['name' => 'Rent', 'category' => 'rent', 'amount' => 100]);

        Livewire::test(MonthlyProfit::class)
            ->assertSet('month', $month)
            ->call('openPay', $rent->id)
            ->assertSet('payAmount', '100')
            ->set('payAmount', '120')        // actual paid differs from the usual
            ->set('payDate', now()->toDateString())
            ->call('savePay')
            ->assertHasNoErrors();

        $payment = $rent->fresh()?->paymentForPeriod($month);
        $this->assertNotNull($payment);
        $this->assertSame(120.0, $payment->amount);

        // Unmark removes it.
        Livewire::test(MonthlyProfit::class)->call('unmarkPaid', $rent->id);
        $this->assertNull($rent->fresh()?->paymentForPeriod($month));
    }

    public function test_can_add_a_recurring_bill(): void
    {
        Livewire::test(MonthlyProfit::class)
            ->call('openAddBill')
            ->set('newBill.name', 'EWA')
            ->set('newBill.category', 'utilities')
            ->set('newBill.amount', '45')
            ->call('addBill')
            ->assertHasNoErrors()
            ->assertSet('addingBill', false);

        $this->assertDatabaseHas('expenses', ['name' => 'EWA', 'category' => 'utilities']);
    }

    public function test_non_admin_is_forbidden(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]));

        Livewire::test(MonthlyProfit::class)->assertForbidden();
    }
}
