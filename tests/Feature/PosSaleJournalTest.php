<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Providers\AccountingServiceProvider;
use Modules\Pos\Enums\OrderState;
use Modules\Pos\Enums\SessionState;
use Modules\Pos\Models\PosCustomerDiscount;
use Modules\Pos\Models\PosOrder;
use Modules\Pos\Models\PosSession;
use Tests\TestCase;

/**
 * Every paid sale must reach the general ledger.
 *
 * With a sales-tax account mapped, the credit side was built from the LIST
 * price (subtotal + tax) while the debit side was what the customer actually
 * paid. Any discount, prepaid credit or delivery charge therefore made the
 * entry unbalanced, JournalPoster refused it, and the sale posted NOTHING —
 * the error going only into the order's own notes.
 */
final class PosSaleJournalTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('pos');
        app(ModuleManager::class)->install('accounting');
        $this->app->register(AccountingServiceProvider::class);
        (new ChartOfAccountsSeeder())->run();

        // A sales-tax account mapped is what exposes the bug.
        config(['accounting.accounts.sales_tax_payable' => '2010']);
    }

    private function openSession(): PosSession
    {
        return PosSession::query()->create([
            'reference' => 'POS-S/0001', 'state' => SessionState::Opened,
            'opening_cash' => 0.0, 'opened_at' => now(),
        ]);
    }

    private function order(float $unitPrice, float $taxRate, int $seq = 1): PosOrder
    {
        $order = PosOrder::query()->create([
            'reference' => sprintf('POS/1/%04d', $seq),
            'pos_session_id' => $this->openSession()->id,
            'state' => OrderState::Draft,
        ]);

        $line = $order->lines()->create([
            'name' => 'Item', 'qty' => 1, 'unit_price' => $unitPrice, 'discount' => 0, 'tax_rate' => $taxRate,
        ]);
        $line->recompute();
        $line->save();
        $order->recalculate();

        return $order->fresh() ?? $order;
    }

    private function assertBalancedEntryFor(PosOrder $order): JournalEntry
    {
        $entry = JournalEntry::query()->where('reference', $order->reference)->first();
        $this->assertNotNull($entry, "no journal entry was posted for {$order->reference}");
        $this->assertTrue($entry->isBalanced());

        $debit = round((float) $entry->items()->sum('debit'), 2);
        $this->assertSame(round((float) $order->total, 2), $debit, 'the entry must book what the customer paid');

        return $entry;
    }

    public function test_a_plain_taxed_sale_posts(): void
    {
        $order = $this->order(unitPrice: 100.0, taxRate: 10.0);
        $order->finalizeSale();

        $this->assertBalancedEntryFor($order->fresh() ?? $order);
    }

    public function test_a_discounted_sale_still_posts(): void
    {
        PosCustomerDiscount::query()->create([
            'phone' => '33445566', 'discount_percent' => 25.0,
            'prepaid_balance' => 0.0, 'active' => true,
        ]);

        $order = $this->order(unitPrice: 100.0, taxRate: 10.0);
        $order->applyCustomerDiscount('33445566');
        $order->finalizeSale();

        $fresh = $order->fresh() ?? $order;
        $this->assertLessThan(110.0, (float) $fresh->total);
        $this->assertBalancedEntryFor($fresh);
    }

    public function test_a_sale_part_paid_with_store_credit_still_posts(): void
    {
        PosCustomerDiscount::query()->create([
            'phone' => '33445566', 'discount_percent' => 0.0,
            'prepaid_balance' => 40.0, 'active' => true,
        ]);

        $order = $this->order(unitPrice: 100.0, taxRate: 10.0);
        $order->applyCustomerDiscount('33445566');
        $order->finalizeSale();

        $this->assertBalancedEntryFor($order->fresh() ?? $order);
    }
}
