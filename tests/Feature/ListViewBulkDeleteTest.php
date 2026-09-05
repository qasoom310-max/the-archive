<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Exceptions\RecordInUseException;
use App\Erp\Modules\ModuleManager;
use App\Livewire\Views\ListView;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Accounting\Enums\AccountType;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Models\JournalItem;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoDriver;
use Modules\Limousine\Models\LimoInvoice;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Models\LimoReceipt;
use Modules\Rental\Models\Driver;
use Modules\Rental\Models\RentalCustomer;
use Tests\TestCase;

/**
 * Deleting from a list page goes through the records, not round them.
 *
 * The bulk delete used to be one SQL statement. That skipped everything a
 * model does when it is deleted (a receipt telling its invoice), and a row the
 * database refused to release came back as a crashed page. And nothing at all
 * stopped a customer with bookings from being deleted — the limousine tables
 * lost their foreign key when customers were merged, so the bookings were
 * simply left pointing at nobody.
 */
final class ListViewBulkDeleteTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $modules = app(ModuleManager::class);
        $modules->install('limousine');
        $modules->install('rental');
        $modules->install('accounting');
    }

    public function test_deleting_receipts_through_the_list_tells_the_invoice(): void
    {
        $customer = LimoCustomer::query()->create(['name' => 'Zain']);
        $invoice = LimoInvoice::query()->create(['customer_id' => $customer->id, 'issue_date' => '2026-06-10', 'total' => 30]);
        $receipt = LimoReceipt::query()->create([
            'invoice_id' => $invoice->id, 'customer_id' => $customer->id,
            'date' => '2026-06-10', 'amount' => 30, 'method' => 'cash',
        ]);
        $this->assertSame(LimoInvoice::STATUS_PAID, $invoice->fresh()?->status);

        Livewire::test(ListView::class, ['model' => LimoReceipt::class, 'modelKey' => 'limousine.receipt'])
            ->set('selected', [$receipt->id])
            ->call('bulkDelete')
            ->assertHasNoErrors();

        $this->assertSame(0, LimoReceipt::query()->count());
        // The invoice heard about it — it owes the money again.
        $fresh = $invoice->fresh();
        $this->assertSame(LimoInvoice::STATUS_UNPAID, $fresh?->status);
        $this->assertSame(0.0, round((float) $fresh?->amount_paid, 3));
    }

    /** The database will not release an account with journal lines; the page says so instead of dying. */
    public function test_an_account_with_journal_lines_is_refused_with_a_message(): void
    {
        $account = Account::query()->create(['code' => '100', 'name' => 'Cash', 'type' => AccountType::Asset]);
        $entry = JournalEntry::query()->create(['number' => 'MISC/2026/0001', 'date' => '2026-06-10']);
        JournalItem::query()->create(['journal_entry_id' => $entry->id, 'account_id' => $account->id, 'debit' => 10, 'credit' => 0]);

        Livewire::test(ListView::class, ['model' => Account::class, 'modelKey' => 'accounting.account'])
            ->set('selected', [$account->id])
            ->call('bulkDelete')
            ->assertOk()
            ->assertHasErrors('selected');

        $this->assertNotNull($account->fresh());
    }

    public function test_a_customer_with_bookings_cannot_be_deleted_from_either_app(): void
    {
        $customer = LimoCustomer::query()->create(['name' => 'Amina Mansoori']);
        $booking = LimoBooking::query()->create([
            'reference' => 'BK/00001', 'customer_id' => $customer->id,
            'pickup_at' => now()->addDay(), 'status' => LimoBooking::STATUS_QUEUE,
        ]);

        // From the limousine customer list…
        Livewire::test(ListView::class, ['model' => LimoCustomer::class, 'modelKey' => 'limousine.customer'])
            ->set('selected', [$customer->id])
            ->call('bulkDelete')
            ->assertHasErrors('selected')
            ->assertSee('Amina Mansoori')
            ->assertSee('limousine bookings (1)');

        // …and from the rental one: same person, same shared table, same rule.
        Livewire::test(ListView::class, ['model' => RentalCustomer::class, 'modelKey' => 'rental.customer'])
            ->set('selected', [$customer->id])
            ->call('bulkDelete')
            ->assertHasErrors('selected');

        $this->assertNotNull($customer->fresh());
        $this->assertSame($customer->id, $booking->fresh()?->customer_id);
    }

    public function test_a_customer_nobody_has_dealt_with_can_go(): void
    {
        $customer = RentalCustomer::query()->create(['name' => 'Nobody Yet']);

        Livewire::test(ListView::class, ['model' => RentalCustomer::class, 'modelKey' => 'rental.customer'])
            ->set('selected', [$customer->id])
            ->call('bulkDelete')
            ->assertHasNoErrors();

        $this->assertNull($customer->fresh());
    }

    /** A driver with trips behind them stays on the books — from whichever class reads the shared table. */
    public function test_a_driver_with_trips_cannot_be_deleted(): void
    {
        $driver = LimoDriver::query()->create(['name' => 'Hamad']);
        $customer = LimoCustomer::query()->create(['name' => 'Zain']);
        $booking = LimoBooking::query()->create([
            'reference' => 'BK/00002', 'customer_id' => $customer->id,
            'pickup_at' => now()->addDay(), 'status' => LimoBooking::STATUS_CONFIRMED,
        ]);
        LimoLeg::query()->create([
            'legable_type' => LimoBooking::class, 'legable_id' => $booking->id,
            'sequence' => 0, 'reference' => '10001', 'status' => LimoLeg::STATUS_CONFIRMED,
            'start_at' => now()->addDay(), 'from_location' => 'Hotel', 'to_location' => 'Airport',
            'rate' => 20, 'net_amount' => 20, 'driver_id' => $driver->id, 'driver' => 'Hamad',
        ]);

        try {
            Driver::query()->findOrFail($driver->id)->delete();
            $this->fail('A driver with trips should refuse to be deleted.');
        } catch (RecordInUseException $e) {
            $this->assertStringContainsString('Hamad', $e->getMessage());
            $this->assertStringContainsString('limousine trips (1)', $e->getMessage());
        }

        $this->assertNotNull($driver->fresh());
    }
}
