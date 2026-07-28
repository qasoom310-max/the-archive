<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Business\Feature;
use App\Erp\Business\Features;
use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Providers\AccountingServiceProvider;
use Modules\Pos\Enums\FulfillmentStatus;
use Modules\Pos\Enums\OrderState;
use Modules\Pos\Enums\SalesChannel;
use Modules\Pos\Enums\SessionState;
use Modules\Pos\Livewire\PosSettlements;
use Modules\Pos\Models\PosOrder;
use Modules\Pos\Models\PosSession;
use Modules\Pos\Models\PosSettlement;
use Modules\Pos\Services\PosSettlementService;
use Tests\TestCase;

/**
 * Delivery money reconciliation.
 *
 * A delivery sale's cash is collected by the delivery company, not by us, so
 * "paid" doesn't mean "in our account". Orders sit in a pending pool until a
 * payout is requested and the money actually lands — and because the company
 * deducts its fee before remitting, the expected amount is the collected total
 * MINUS those fees. A transfer short by exactly the fees is correct, not a
 * discrepancy.
 */
final class PosSettlementTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('pos');
        Features::setOverrides([Feature::RemoteSales->value => true]);
    }

    private ?PosSession $session = null;

    /** One shared open register for the whole test (the reference is unique). */
    private function openSession(): PosSession
    {
        return $this->session ??= PosSession::query()->create([
            'reference' => 'POS-S/0001',
            'state' => SessionState::Opened,
            'opening_cash' => 0.0,
            'opened_at' => now(),
        ]);
    }

    /** A delivered, fully-paid remote order — money now sits with the company. */
    private function collectedOrder(string $ref, float $total, float $fee): PosOrder
    {
        return PosOrder::query()->create([
            'pos_session_id' => $this->openSession()->id,
            'reference' => $ref,
            'state' => OrderState::Done->value,
            'channel' => SalesChannel::Remote->value,
            'fulfillment_status' => FulfillmentStatus::Delivered->value,
            'total' => $total,
            'paid_total' => $total,
            'delivery_fee' => $fee,
            'ordered_at' => now(),
        ]);
    }

    public function test_collected_delivery_orders_wait_in_the_pending_pool(): void
    {
        $this->collectedOrder('POS/1', 20.0, 1.1);
        $this->collectedOrder('POS/2', 30.0, 1.1);

        // An unpaid COD order is NOT pending — nobody is holding our money yet.
        $unpaid = $this->collectedOrder('POS/3', 15.0, 1.1);
        $unpaid->update(['paid_total' => 0]);

        // A walk-in sale is cash in the drawer, not in transit.
        PosOrder::query()->create([
            'pos_session_id' => $this->openSession()->id,
            'reference' => 'POS/4', 'state' => OrderState::Done->value,
            'channel' => SalesChannel::Shop->value, 'total' => 5.0, 'paid_total' => 5.0, 'ordered_at' => now(),
        ]);

        $summary = app(PosSettlementService::class)->pendingSummary();

        $this->assertSame(2, $summary['orders']);
        $this->assertSame(50.0, $summary['collected']);
        $this->assertSame(2.2, $summary['fees']);
        // They keep their fees, so this is what should actually arrive.
        $this->assertSame(47.8, $summary['expected']);
    }

    public function test_requesting_a_payout_snapshots_the_expected_amount_and_claims_the_orders(): void
    {
        $this->collectedOrder('POS/1', 20.0, 1.1);
        $this->collectedOrder('POS/2', 30.0, 1.1);

        $settlement = app(PosSettlementService::class)->requestPayout();

        $this->assertNotNull($settlement);
        $this->assertSame(50.0, $settlement->collected_total);
        $this->assertSame(2.2, $settlement->fees_deducted);
        $this->assertSame(47.8, $settlement->expected_amount);
        $this->assertSame(2, $settlement->orders()->count());

        // Those orders leave the pending pool — no double-requesting.
        $this->assertSame(0, app(PosSettlementService::class)->pendingSummary()['orders']);
    }

    public function test_an_exact_transfer_reconciles_with_no_discrepancy(): void
    {
        $this->collectedOrder('POS/1', 20.0, 1.1);
        $service = app(PosSettlementService::class);
        $settlement = $service->requestPayout();
        $this->assertNotNull($settlement);

        $service->recordReceipt($settlement, 18.9, 'bank_transfer');

        $settlement->refresh();
        $this->assertTrue($settlement->isReceived());
        $this->assertSame(18.9, $settlement->received_amount);
        $this->assertSame(0.0, $settlement->difference);
        $this->assertFalse($settlement->hasDiscrepancy());
    }

    public function test_a_short_transfer_is_flagged_with_the_shortfall(): void
    {
        $this->collectedOrder('POS/1', 20.0, 1.1);
        $service = app(PosSettlementService::class);
        $settlement = $service->requestPayout();
        $this->assertNotNull($settlement);

        // Expected 18.9 but only 15.0 arrived.
        $service->recordReceipt($settlement, 15.0, 'bank_transfer');

        $settlement->refresh();
        $this->assertTrue($settlement->hasDiscrepancy());
        $this->assertSame(-3.9, $settlement->difference);
        $this->assertSame(3.9, $settlement->shortfall());
    }

    public function test_cancelling_a_request_returns_its_orders_to_the_pending_pool(): void
    {
        $this->collectedOrder('POS/1', 20.0, 1.1);
        $service = app(PosSettlementService::class);
        $settlement = $service->requestPayout();
        $this->assertNotNull($settlement);

        $service->cancel($settlement);

        $this->assertSame(1, $service->pendingSummary()['orders']);
        $this->assertSame(0, PosSettlement::query()->count());
    }

    public function test_the_screen_requests_a_payout_and_records_the_money(): void
    {
        $this->collectedOrder('POS/1', 20.0, 1.1);

        Livewire::test(PosSettlements::class)
            ->call('requestPayout')
            ->assertSee('SET/0001');

        $settlement = PosSettlement::query()->firstOrFail();

        Livewire::test(PosSettlements::class)
            ->call('openReceive', $settlement->id)
            // Prefilled with what we expect — usually an exact match.
            ->assertSet('receivedAmount', '18.900')
            ->call('confirmReceive');

        $this->assertTrue($settlement->fresh()?->isReceived());
    }

    public function test_the_screen_is_gated_to_the_remote_sales_feature(): void
    {
        Features::setOverrides([Feature::RemoteSales->value => false]);

        Livewire::test(PosSettlements::class)->assertRedirect(url('/app/pos'));
    }

    /**
     * The books must say the money is NOT ours until it lands: a delivery sale
     * debits "money in transit", the company's deducted fee comes back out of
     * that same balance, and the payout clears it into the bank.
     */
    public function test_the_ledger_moves_delivery_money_through_transit_into_the_bank(): void
    {
        app(ModuleManager::class)->install('accounting');
        $this->app->register(AccountingServiceProvider::class);
        (new ChartOfAccountsSeeder())->run();

        $transit = Account::byCode('1150');
        $bank = Account::byCode('1020');
        $cash = Account::byCode('1010');
        $this->assertNotNull($transit);
        $this->assertNotNull($bank);
        $this->assertNotNull($cash);

        $order = $this->collectedOrder('POS/1', 20.0, 1.1);
        $order->update(['paid_total' => 0]);
        // Collecting fires PosOrderPaid → sale + delivery-fee postings.
        Livewire::test(\Modules\Pos\Livewire\RemoteOrders::class)->call('collectPayment', $order->id);

        $debit = fn (Account $a): float => round((float) \Modules\Accounting\Models\JournalItem::query()
            ->where('account_id', $a->id)->sum('debit'), 2);
        $credit = fn (Account $a): float => round((float) \Modules\Accounting\Models\JournalItem::query()
            ->where('account_id', $a->id)->sum('credit'), 2);

        // Sale went to transit, not cash — the delivery company holds it.
        $this->assertSame(20.0, $debit($transit));
        $this->assertSame(0.0, $debit($cash));
        // Their fee is netted off what they owe us, not paid from our drawer.
        $this->assertSame(1.1, $credit($transit));
        $this->assertSame(0.0, $credit($cash));

        // Payout of exactly what's owed clears transit into the bank.
        $service = app(PosSettlementService::class);
        $settlement = $service->requestPayout();
        $this->assertNotNull($settlement);
        $service->recordReceipt($settlement, 18.9, 'bank_transfer');

        $this->assertNotNull(JournalEntry::query()->where('reference', 'STL/' . $settlement->reference)->first());
        $this->assertSame(18.9, $debit($bank));
        // 20 in, 1.1 fee + 18.9 payout out → the balance settles to zero.
        $this->assertSame(20.0, $credit($transit));
        $this->assertSame(0.0, round($debit($transit) - $credit($transit), 2));
    }
}
