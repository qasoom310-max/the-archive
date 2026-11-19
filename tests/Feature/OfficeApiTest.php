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
