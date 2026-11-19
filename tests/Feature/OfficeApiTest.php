<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Erp\Office\OfficeTokens;
use App\Erp\Tenancy\WorkspaceManager;
use App\Http\Middleware\OfficeApi;
use App\Models\ActivityLog;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Modules\Accounting\Enums\AccountType;
use Modules\Accounting\Enums\JournalEntryState;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Models\JournalItem;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoInvoice;
use Modules\Limousine\Models\LimoLeg;
use Modules\Rental\Models\RentalCustomer;
use Modules\Rental\Models\RentalOrder;
use Modules\Rental\Models\Vehicle;
use Tests\TestCase;

/**
 * The AI office's read-only API (/api/office/v1/…): only an office key opens
 * it, only for the databases the key lists, only to read — enforced by the
 * database itself, not just by which routes exist.
 */
final class OfficeApiTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-19 10:00:00');
        app(ModuleManager::class)->install('accounting');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        foreach (glob(storage_path('app/workspaces') . '/*.sqlite') ?: [] as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    private function main(): Workspace
    {
        return app(WorkspaceManager::class)->ensureMain();
    }

    /** @param list<Workspace> $workspaces */
    private function key(array $workspaces): string
    {
        [, $plain] = app(OfficeTokens::class)->issue('AI office', array_map(static fn (Workspace $w): int => (int) $w->id, $workspaces));

        return $plain;
    }

    /** @return array<string, string> */
    private function headers(string $key, Workspace $workspace): array
    {
        return ['Authorization' => 'Bearer ' . $key, 'X-Workspace' => (string) $workspace->id, 'Accept' => 'application/json'];
    }

    private function entry(string $number, string $date, float $amount, JournalEntryState $state, ?int $userId = null): JournalEntry
    {
        $cash = Account::query()->firstOrCreate(['code' => '100'], ['name' => 'Cash', 'type' => AccountType::Asset]);
        $sales = Account::query()->firstOrCreate(['code' => '400'], ['name' => 'Sales', 'type' => AccountType::Income]);
        $entry = JournalEntry::query()->create([
            'number' => $number, 'date' => $date, 'state' => $state, 'user_id' => $userId,
            'posted_at' => $state === JournalEntryState::Posted ? $date . ' 12:00:00' : null,
        ]);
        JournalItem::query()->create(['journal_entry_id' => $entry->id, 'account_id' => $cash->id, 'debit' => $amount, 'credit' => 0]);
        JournalItem::query()->create(['journal_entry_id' => $entry->id, 'account_id' => $sales->id, 'debit' => 0, 'credit' => $amount]);

        return $entry;
    }

    // ── Who may come in ─────────────────────────────────────────────

    public function test_without_a_live_office_key_nothing_is_read(): void
    {
        $main = $this->main();

        $this->getJson('/api/office/v1/workspace', ['X-Workspace' => (string) $main->id])->assertStatus(401);
        $this->getJson('/api/office/v1/workspace', ['Authorization' => 'Bearer office_wrong', 'X-Workspace' => (string) $main->id])->assertStatus(401);

        // A staff sign-in token is not an office key, whatever that person may do.
        $admin = User::factory()->create(['is_admin' => true, 'is_super_admin' => true]);
        $staffToken = $admin->createToken('phone')->plainTextToken;
        $this->getJson('/api/office/v1/workspace', ['Authorization' => 'Bearer ' . $staffToken, 'X-Workspace' => (string) $main->id])->assertStatus(401);

        // Revoked: stops at once.
        [$token, $plain] = app(OfficeTokens::class)->issue('Old', [(int) $main->id]);
        $this->getJson('/api/office/v1/workspace', $this->headers($plain, $main))->assertOk();
        app(OfficeTokens::class)->revoke($token);
        $this->getJson('/api/office/v1/workspace', $this->headers($plain, $main))->assertStatus(401);
    }

    public function test_a_key_reads_only_the_workspaces_it_lists_and_must_name_one(): void
    {
        $main = $this->main();
        $owner = User::factory()->create(['email' => 'owner@erp.test', 'is_admin' => true, 'is_super_admin' => true]);
        $other = app(WorkspaceManager::class)->provision('Other business', $owner, ['accounting']);
        $key = $this->key([$main]);

        $this->getJson('/api/office/v1/workspace', ['Authorization' => 'Bearer ' . $key])->assertStatus(403);
        $this->getJson('/api/office/v1/workspace', $this->headers($key, $other))->assertStatus(403);
        $this->getJson('/api/office/v1/workspace', $this->headers($key, $main))
            ->assertOk()
            ->assertJsonPath('id', $main->id)
            ->assertJsonPath('accounting', true);
    }

    public function test_it_only_answers_get(): void
    {
        $main = $this->main();
        $key = $this->key([$main]);

        $this->postJson('/api/office/v1/staff', [], $this->headers($key, $main))->assertStatus(405);
        $this->deleteJson('/api/office/v1/staff', [], $this->headers($key, $main))->assertStatus(405);
    }

    // ── Read only, by force ─────────────────────────────────────────

    public function test_the_database_refuses_writes_during_an_office_request_and_allows_them_after(): void
    {
        $main = $this->main();
        $key = $this->key([$main]);

        // A read endpoint with a bug in it: it tries to write.
        Route::middleware(OfficeApi::class)->get('/api/office/v1/_test-write', static function (): array {
            try {
                DB::table('activity_logs')->insert(['user_name' => 'x', 'user_is_admin' => false, 'action' => 'created', 'created_at' => now()]);

                return ['wrote' => true];
            } catch (\Throwable) {
                return ['wrote' => false];
            }
        });

        $this->getJson('/api/office/v1/_test-write', $this->headers($key, $main))
            ->assertOk()
            ->assertJsonPath('wrote', false);
        $this->assertSame(0, ActivityLog::query()->count());

        // The lock is lifted for everything after the request.
        ActivityLog::query()->create(['user_name' => 'Owner', 'user_is_admin' => true, 'action' => 'login', 'created_at' => now()]);
        $this->assertSame(1, ActivityLog::query()->count());
    }

    public function test_a_business_database_is_read_and_not_main(): void
    {
        $main = $this->main();
        $owner = User::factory()->create(['email' => 'owner@erp.test', 'is_admin' => true, 'is_super_admin' => true]);
        $manager = app(WorkspaceManager::class);
        $business = $manager->provision('Wanaan', $owner, ['accounting']);

        $this->entry('MAIN/0001', '2026-11-10', 5, JournalEntryState::Posted);
        $manager->withTenant((string) $business->databasePath(), function (): void {
            $this->entry('WAN/0001', '2026-11-10', 7, JournalEntryState::Posted);
        });

        $key = $this->key([$main, $business]);

        $this->getJson('/api/office/v1/accounting/journal-entries', $this->headers($key, $business))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.number', 'WAN/0001');

        $this->getJson('/api/office/v1/accounting/journal-entries', $this->headers($key, $main))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.number', 'MAIN/0001');

        // Main is the default again, and writable.
        $this->assertSame(Workspace::$landlordConnection, DB::getDefaultConnection());
        $this->entry('MAIN/0002', '2026-11-11', 1, JournalEntryState::Draft);
    }

    // ── What it says ────────────────────────────────────────────────

    public function test_staff_are_listed_without_emails(): void
    {
        $main = $this->main();
        User::factory()->create(['name' => 'Fatima', 'email' => 'fatima@example.test', 'is_accountant' => true]);

        $response = $this->getJson('/api/office/v1/staff', $this->headers($this->key([$main]), $main))->assertOk();

        $response->assertJsonPath('data.0.name', 'Fatima')->assertJsonPath('data.0.is_accountant', true);
        $this->assertStringNotContainsString('fatima@example.test', (string) $response->getContent());
        $this->assertStringNotContainsString('password', (string) $response->getContent());
    }

    public function test_activity_hides_ip_addresses_and_vault_details_and_filters_by_day(): void
    {
        $main = $this->main();
        ActivityLog::query()->create(['user_name' => 'Ali', 'user_is_admin' => false, 'action' => 'created', 'subject' => 'Invoice', 'description' => 'INV/0042', 'ip_address' => '10.1.2.3', 'created_at' => '2026-11-18 09:00:00']);
        ActivityLog::query()->create(['user_name' => 'Ali', 'user_is_admin' => false, 'action' => 'vault_revealed', 'description' => 'Bank portal login', 'created_at' => '2026-11-18 10:00:00']);
        ActivityLog::query()->create(['user_name' => 'Ali', 'user_is_admin' => false, 'action' => 'created', 'created_at' => '2026-11-17 23:59:59']);

        $response = $this->getJson('/api/office/v1/activity?from=2026-11-18&to=2026-11-18', $this->headers($this->key([$main]), $main))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.description', 'INV/0042')
            ->assertJsonPath('data.1.action', 'vault_revealed')
            ->assertJsonPath('data.1.description', null);

        $this->assertStringNotContainsString('10.1.2.3', (string) $response->getContent());
        $this->assertStringNotContainsString('Bank portal', (string) $response->getContent());
    }

    public function test_journal_entries_carry_who_made_them_their_totals_and_page_through(): void
    {
        $main = $this->main();
        $ali = User::factory()->create(['name' => 'Ali']);
        $this->entry('MISC/0001', '2026-11-01', 10.5, JournalEntryState::Posted, $ali->id);
        $this->entry('MISC/0002', '2026-11-12', 20, JournalEntryState::Draft, $ali->id);
        $this->entry('MISC/0003', '2026-11-13', 30, JournalEntryState::Draft);
        $key = $this->key([$main]);

        $this->getJson('/api/office/v1/accounting/journal-entries?state=draft&limit=1', $this->headers($key, $main))
            ->assertOk()
            ->assertJsonPath('data.0.number', 'MISC/0002')
            ->assertJsonPath('data.0.user_name', 'Ali')
            ->assertJsonPath('data.0.total_debit', 20)
            ->assertJsonPath('data.0.balanced', true)
            ->assertJsonPath('data.0.posted_at', null)
            ->assertJsonPath('next_after_id', 2);

        $this->getJson('/api/office/v1/accounting/journal-entries?state=draft&limit=1&after_id=2', $this->headers($key, $main))
            ->assertOk()
            ->assertJsonPath('data.0.number', 'MISC/0003')
            ->assertJsonPath('next_after_id', null);

        $this->getJson('/api/office/v1/accounting/journal-entries?from=2026-11-01&to=2026-11-01', $this->headers($key, $main))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.total_credit', 10.5);

        $this->getJson('/api/office/v1/accounting/journal-entries?from=19-11-2026', $this->headers($key, $main))->assertStatus(422);
        $this->getJson('/api/office/v1/accounting/journal-entries?state=deleted', $this->headers($key, $main))->assertStatus(422);
        $this->getJson('/api/office/v1/accounting/journal-entries?limit=5000', $this->headers($key, $main))->assertStatus(422);
    }

    // ── Sections: limousine and rent a car ──────────────────────────

    public function test_the_limousine_manager_reads_trips_invoices_and_expenses_without_private_details(): void
    {
        app(ModuleManager::class)->install('limousine');
        $main = $this->main();
        $customer = LimoCustomer::query()->create(['name' => 'Ministry Travel Desk', 'phone' => '39998877', 'email' => 'desk@example.test', 'cpr' => '880101234']);
        $booking = LimoBooking::query()->create(['reference' => 'BK/1001', 'customer_id' => $customer->id, 'pickup_at' => '2026-11-18 18:00:00', 'status' => LimoBooking::STATUS_CONFIRMED]);
        $booking->forceFill(['pax_contact' => '33445566'])->saveQuietly();
        LimoLeg::query()->create(['legable_type' => LimoBooking::class, 'legable_id' => $booking->id, 'sequence' => 0, 'reference' => '30777', 'status' => LimoLeg::STATUS_COMPLETED, 'start_at' => '2026-11-18 18:00:00', 'from_location' => 'Seef', 'to_location' => 'Bahrain Airport', 'rate' => 12, 'net_amount' => 12]);
        LimoLeg::query()->create(['legable_type' => LimoBooking::class, 'legable_id' => $booking->id, 'sequence' => 1, 'reference' => '30778', 'status' => LimoLeg::STATUS_CANCELLED, 'start_at' => '2026-11-10 09:00:00', 'from_location' => 'Airport', 'to_location' => 'Seef', 'rate' => 12, 'net_amount' => 12]);
        LimoInvoice::query()->create(['reference' => 'INV/0042', 'customer_id' => $customer->id, 'booking_id' => $booking->id, 'issue_date' => '2026-11-01', 'due_date' => '2026-11-09', 'total' => 100, 'amount_paid' => 40, 'status' => LimoInvoice::STATUS_PARTIAL]);
        $key = $this->key([$main]);

        $trips = $this->getJson('/api/office/v1/limousine/trips?from=2026-11-15&to=2026-11-19', $this->headers($key, $main))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', '30777')
            ->assertJsonPath('data.0.booking', 'BK/1001')
            ->assertJsonPath('data.0.customer', 'Ministry Travel Desk')
            ->assertJsonPath('data.0.status', 'completed');
        $this->getJson('/api/office/v1/limousine/trips?status=cancelled', $this->headers($key, $main))->assertJsonCount(1, 'data');
        $this->getJson('/api/office/v1/limousine/trips?status=lost', $this->headers($key, $main))->assertStatus(422);

        $invoices = $this->getJson('/api/office/v1/limousine/invoices', $this->headers($key, $main))
            ->assertOk()
            ->assertJsonPath('data.0.balance', 60)
            ->assertJsonPath('data.0.days_overdue', 10);
        $this->getJson('/api/office/v1/limousine/expenses', $this->headers($key, $main))->assertOk()->assertJsonCount(0, 'data');

        foreach ([$trips, $invoices] as $response) {
            foreach (['39998877', 'desk@example.test', '880101234', '33445566'] as $private) {
                $this->assertStringNotContainsString($private, (string) $response->getContent());
            }
        }
    }

    public function test_the_rent_a_car_manager_reads_rentals_overlapping_the_period_and_the_fleet_without_private_details(): void
    {
        app(ModuleManager::class)->install('rental');
        $main = $this->main();
        $car = Vehicle::query()->create(['name' => 'Yaris', 'plate_no' => '123456', 'daily_rate' => 10]);
        $car->forceFill(['insurance_expiry' => '2026-12-01', 'next_maintenance_date' => '2026-11-25'])->save();
        $order = fn (string $name, string $start, string $end, string $state) => RentalOrder::query()->create([
            'customer_id' => RentalCustomer::query()->create(['name' => $name, 'phone' => '36665544', 'cpr' => '900101111'])->id,
            'vehicle_id' => $car->id, 'start_date' => $start, 'end_date' => $end,
            'rate_type' => 'daily', 'rate' => 10, 'deposit' => 50, 'state' => $state,
        ]);
        $order('Before', '2026-10-01', '2026-10-05', RentalOrder::STATE_CLOSED);
        $order('Running across', '2026-11-10', '2026-11-25', RentalOrder::STATE_ACTIVE);
        $order('Inside', '2026-11-16', '2026-11-17', RentalOrder::STATE_CLOSED);
        $key = $this->key([$main]);

        $rentals = $this->getJson('/api/office/v1/rental/orders?from=2026-11-15&to=2026-11-19', $this->headers($key, $main))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.customer', 'Running across')
            ->assertJsonPath('data.0.car', 'Yaris 123456')
            ->assertJsonPath('data.0.deposit', 50);
        $this->getJson('/api/office/v1/rental/orders?state=active', $this->headers($key, $main))->assertJsonCount(1, 'data');
        $this->getJson('/api/office/v1/rental/orders?state=lost', $this->headers($key, $main))->assertStatus(422);

        $this->getJson('/api/office/v1/rental/fleet', $this->headers($key, $main))
            ->assertOk()
            ->assertJsonPath('data.0.plate', '123456')
            ->assertJsonPath('data.0.insurance_expiry', '2026-12-01')
            ->assertJsonPath('data.0.next_service_date', '2026-11-25');

        foreach (['36665544', '900101111'] as $private) {
            $this->assertStringNotContainsString($private, (string) $rentals->getContent());
        }
    }

    public function test_the_accounts_manager_reads_money_in_and_out_and_payroll_only_as_a_monthly_total(): void
    {
        app(ModuleManager::class)->install('limousine');
        $main = $this->main();
        $customer = LimoCustomer::query()->create(['name' => 'Hotel Desk']);
        DB::table('limo_receipts')->insert(['reference' => 'RCP/0001', 'customer_id' => $customer->id, 'date' => '2026-11-10', 'amount' => 120.5, 'method' => 'cash', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('limo_receipts')->insert(['reference' => 'RCP/0002', 'customer_id' => $customer->id, 'date' => '2026-09-01', 'amount' => 99, 'method' => 'cash', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('limo_expenses')->insert(['reference' => 'EXP/0001', 'date' => '2026-11-11', 'category' => 'fuel', 'amount' => 15, 'payee' => 'Bapco', 'created_at' => now(), 'updated_at' => now()]);
        $employee = DB::table('employees')->insertGetId(['name' => 'Salary Secret Person', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('payslips')->insert(['employee_id' => $employee, 'period' => '2026-11', 'basic' => 731, 'net' => 731.25, 'paid_on' => '2026-11-15', 'created_at' => now(), 'updated_at' => now()]);
        $key = $this->key([$main]);

        $this->getJson('/api/office/v1/money/in?from=2026-11-01&to=2026-11-19', $this->headers($key, $main))
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('total', 120.5)
            ->assertJsonPath('data.0.customer', 'Hotel Desk');

        $out = $this->getJson('/api/office/v1/money/out?from=2026-11-01&to=2026-11-19', $this->headers($key, $main))
            ->assertOk()
            ->assertJsonPath('count', 2)
            ->assertJsonPath('data.0.source', 'limousine expense')
            ->assertJsonPath('data.0.note', 'no bill attached')
            ->assertJsonPath('data.1.source', 'payroll')
            ->assertJsonPath('data.1.payee', '1 staff');
        $this->assertStringNotContainsString('Salary Secret Person', (string) $out->getContent());

        $this->getJson('/api/office/v1/money/out', $this->headers($key, $main))->assertStatus(422);
        $this->getJson('/api/office/v1/money/in?from=2026-01-01&to=2026-11-19', $this->headers($key, $main))->assertStatus(422);
    }

    public function test_a_section_that_is_not_installed_says_so(): void
    {
        $main = $this->main();
        $key = $this->key([$main]);

        $this->getJson('/api/office/v1/limousine/trips', $this->headers($key, $main))->assertNotFound()->assertJsonPath('error', 'Limousine is not installed in this workspace.');
        $this->getJson('/api/office/v1/rental/fleet', $this->headers($key, $main))->assertNotFound();
    }

    // ── Keys from the command line ──────────────────────────────────

    public function test_keys_are_created_listed_and_revoked_from_the_command_line(): void
    {
        $main = $this->main();

        $this->artisan('office:key', ['action' => 'create', 'target' => 'AI office'])->assertFailed();
        $this->artisan('office:key', ['action' => 'create', 'target' => 'AI office', '--workspace' => ['nope']])->assertFailed();
        $this->artisan('office:key', ['action' => 'create', 'target' => 'AI office', '--workspace' => ['main']])
            ->expectsOutputToContain('may read workspace(s): ' . $main->id)
            ->assertSuccessful();

        $this->assertSame(1, \App\Models\OfficeToken::query()->count());
        $this->assertSame(64, strlen(\App\Models\OfficeToken::query()->sole()->token_hash));

        $this->artisan('office:key', ['action' => 'revoke', 'target' => '1'])->assertSuccessful();
        $this->assertTrue(\App\Models\OfficeToken::query()->sole()->isRevoked());
        $this->artisan('office:key', ['action' => 'list'])->expectsOutputToContain('revoked')->assertSuccessful();
    }
}
