<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Limousine\Livewire\CustomerSummary;
use Modules\Limousine\Livewire\Receipts;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoReceipt;
use Modules\Limousine\Services\BookingPayments;
use Tests\TestCase;

/**
 * Writing a receipt says money was taken; confirming says it ARRIVED.
 *
 * Cash can miss the drawer, and an online payment can fail after the screen
 * said otherwise. So every receipt starts unconfirmed, and closing it is the
 * accountant's job — with super-admins, and deliberately not regular admins:
 * taking money and vouching it arrived should not be the same pair of hands.
 */
final class LimoPaymentConfirmationTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleManager::class)->install('limousine');
    }

    private function asAccountant(): User
    {
        $user = User::factory()->create(['is_admin' => true, 'is_accountant' => true, 'name' => 'Amal']);
        $this->actingAs($user);

        return $user;
    }

    private function asAdmin(): User
    {
        $user = User::factory()->create(['is_admin' => true]);
        $this->actingAs($user);

        return $user;
    }

    /** A paid trip, which writes a receipt through the normal path. */
    private function receipt(string $method = 'cash', float $amount = 45): LimoReceipt
    {
        $customer = LimoCustomer::query()->firstOrCreate(['name' => 'Dadabhai Travel'], ['type' => 'company']);
        $booking = LimoBooking::query()->create(['customer_id' => $customer->id, 'fare' => $amount]);
        $booking->syncInvoice();
        app(BookingPayments::class)->receive($booking, $amount, $method);

        return LimoReceipt::query()->latest('id')->firstOrFail();
    }

    public function test_a_new_receipt_starts_unconfirmed(): void
    {
        $this->asAdmin();

        $this->assertFalse($this->receipt()->isConfirmed());
    }

    public function test_the_accountant_confirms_cash_and_is_named_on_it(): void
    {
        $this->asAccountant();
        $receipt = $this->receipt('cash');

        Livewire::test(Receipts::class)
            ->call('openConfirm', $receipt->id)
            ->call('saveConfirm')
            ->assertHasNoErrors();

        $fresh = $receipt->fresh();
        $this->assertTrue($fresh?->isConfirmed());
        $this->assertSame('Amal', $fresh?->confirmed_by);
        // Cash is vouched against the drawer — there is no statement to cite.
        $this->assertNull($fresh?->statement_date);
    }

    public function test_a_bank_payment_records_the_statement_date_it_was_found_under(): void
    {
        $this->asAccountant();
        $receipt = $this->receipt('transfer');

        Livewire::test(Receipts::class)
            ->call('openConfirm', $receipt->id)
            ->set('statementDate', '2026-09-03')
            ->call('saveConfirm')
            ->assertHasNoErrors();

        // "I saw it on the statement" without saying where is not something
        // anyone can check later.
        $this->assertSame('2026-09-03', $receipt->fresh()?->statement_date?->format('Y-m-d'));
    }

    public function test_a_regular_admin_cannot_confirm(): void
    {
        $this->asAdmin();
        $receipt = $this->receipt();

        Livewire::test(Receipts::class)
            ->call('openConfirm', $receipt->id)
            ->assertForbidden();

        $this->assertFalse($receipt->fresh()?->isConfirmed());
    }

    public function test_a_bulk_payment_is_one_confirmation_not_forty(): void
    {
        $this->asAccountant();
        $customer = LimoCustomer::query()->create(['name' => 'Dadabhai Travel', 'type' => 'company']);
        foreach ([400, 400, 100] as $fare) {
            $booking = LimoBooking::query()->create(['customer_id' => $customer->id, 'fare' => $fare]);
            $booking->syncInvoice();
        }

        // 900 in one lump across three invoices, off the customer page.
        Livewire::test(CustomerSummary::class, ['id' => $customer->id])
            ->call('openPay')
            ->set('payAmount', '900')
            ->set('payMethod', 'transfer')
            ->call('savePay')
            ->assertHasNoErrors();

        $batch = LimoReceipt::query()->whereNotNull('batch_id')->pluck('batch_id')->unique();
        $this->assertCount(1, $batch, 'the three slices share one batch');

        Livewire::test(Receipts::class)
            ->call('openConfirmBatch', (string) $batch->first())
            ->set('statementDate', '2026-09-03')
            ->call('saveConfirm')
            ->assertHasNoErrors();

        // One press closed the lot, each slice carrying the same evidence.
        $this->assertSame(0, LimoReceipt::query()->whereNull('confirmed_at')->count());
        $this->assertSame(3, LimoReceipt::query()->whereDate('statement_date', '2026-09-03')->count());
    }

    public function test_the_desk_shows_a_bulk_payment_as_one_row(): void
    {
        $this->asAccountant();
        $customer = LimoCustomer::query()->create(['name' => 'Dadabhai Travel', 'type' => 'company']);
        foreach ([400, 100] as $fare) {
            LimoBooking::query()->create(['customer_id' => $customer->id, 'fare' => $fare])->syncInvoice();
        }

        Livewire::test(CustomerSummary::class, ['id' => $customer->id])
            ->call('openPay')
            ->set('payAmount', '500')
            ->call('savePay');

        Livewire::test(Receipts::class)
            ->set('tab', 'to_confirm')
            ->assertSee(__('Bulk payment'))
            ->assertSee(__(':count receipts', ['count' => 2]));
    }

    public function test_a_confirmation_can_be_taken_back(): void
    {
        $this->asAccountant();
        $receipt = $this->receipt('cash');

        Livewire::test(Receipts::class)
            ->call('openConfirm', $receipt->id)
            ->call('saveConfirm')
            ->call('unconfirm', $receipt->id);

        // A mistake in confirming, not a deletion of history — the receipt and
        // its money are untouched.
        $fresh = $receipt->fresh();
        $this->assertFalse($fresh?->isConfirmed());
        $this->assertEqualsWithDelta(45.0, (float) $fresh?->amount, 0.001);
    }

    public function test_the_accountant_lands_on_their_queue_and_staff_on_the_list(): void
    {
        $this->asAccountant();
        Livewire::test(Receipts::class)->assertSet('tab', 'to_confirm');

        $this->asAdmin();
        Livewire::test(Receipts::class)->assertSet('tab', 'all');
    }

    public function test_receipts_from_before_the_rule_are_not_dumped_on_the_desk(): void
    {
        $this->asAccountant();

        // Rows written under the old rules, then the migration runs over them.
        $receipt = $this->receipt('cash');
        $migration = require __DIR__ . '/../../Modules/Limousine/database/migrations/2026_09_01_950029_add_confirmation_to_limo_receipts.php';
        $migration->up();

        $this->assertTrue($receipt->fresh()?->isConfirmed());
        $this->assertSame('System — before confirmations existed', $receipt->fresh()?->confirmed_by);
    }
}
