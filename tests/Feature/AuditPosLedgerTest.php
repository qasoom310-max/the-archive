<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Erp\Tenancy\WorkspaceManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Modules\Accounting\Models\JournalEntry;
use Modules\Pos\Enums\OrderState;
use Modules\Pos\Enums\SessionState;
use Modules\Pos\Models\PosOrder;
use Modules\Pos\Models\PosSession;
use Tests\TestCase;

/**
 * `pos:audit-ledger` — finds journal entries whose POS order no longer exists,
 * and (only when explicitly asked twice) clears them.
 *
 * These orphans are what the ListView mass-delete bug left behind: the order was
 * destroyed, the ledger entry stayed, and Accounting kept counting the sale.
 * `pos:clear-sales` cannot reach them because it walks existing orders, so a shop
 * being set up had no way to reach a genuinely clean slate.
 *
 * The default is a report. Deleting requires --purge AND --confirm, because on a
 * live shop these entries are the surviving record of real revenue.
 */
final class AuditPosLedgerTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('pos');
        app(ModuleManager::class)->install('accounting');
    }

    /**
     * One live sale (order + its entry) and one orphan (entry whose order was
     * destroyed) — the exact shape the bug produced.
     */
    private function seedLedger(): void
    {
        $session = PosSession::query()->create([
            'reference' => 'POS-S/0001',
            'state' => SessionState::Opened,
            'opening_cash' => 0.0,
            'opened_at' => now(),
        ]);

        PosOrder::query()->create([
            'reference' => 'POS/1/0001',
            'pos_session_id' => $session->id,
            'state' => OrderState::Done->value,
            'total' => 20,
            'ordered_at' => now(),
        ]);

        // Survivor: its order is alive.
        JournalEntry::query()->create([
            'number' => 'SALE/2026/0001', 'date' => now()->toDateString(),
            'reference' => 'POS/1/0001', 'state' => 'posted',
        ]);

        // Orphans: no such order any more (sale + its delivery cost).
        JournalEntry::query()->create([
            'number' => 'SALE/2026/0002', 'date' => now()->toDateString(),
            'reference' => 'POS/1/0002', 'state' => 'posted',
        ]);
        JournalEntry::query()->create([
            'number' => 'SALE/2026/0003', 'date' => now()->toDateString(),
            'reference' => 'DEL/POS/1/0002', 'state' => 'posted',
        ]);
    }

    private function mainWorkspaceId(): int
    {
        return app(WorkspaceManager::class)->ensureMain()->id;
    }

    public function test_it_reports_orphans_without_deleting_anything(): void
    {
        $this->seedLedger();

        $this->artisan('pos:audit-ledger', ['--workspace' => $this->mainWorkspaceId()])
            ->assertExitCode(0);

        $this->assertSame(3, JournalEntry::query()->count(), 'A plain audit must not delete.');
    }

    public function test_purge_without_confirm_is_a_dry_run(): void
    {
        $this->seedLedger();

        $this->artisan('pos:audit-ledger', [
            '--workspace' => $this->mainWorkspaceId(),
            '--purge' => true,
        ])->assertExitCode(0);

        $this->assertSame(3, JournalEntry::query()->count(), '--purge alone must delete nothing.');
    }

    public function test_purge_with_confirm_clears_only_the_orphans(): void
    {
        $this->seedLedger();

        $this->artisan('pos:audit-ledger', [
            '--workspace' => $this->mainWorkspaceId(),
            '--purge' => true,
            '--confirm' => true,
        ])->assertExitCode(0);

        // Both orphans gone — the sale entry and its delivery-cost entry.
        $this->assertDatabaseMissing('journal_entries', ['reference' => 'POS/1/0002']);
        $this->assertDatabaseMissing('journal_entries', ['reference' => 'DEL/POS/1/0002']);

        // The entry whose order is still alive is untouched.
        $this->assertDatabaseHas('journal_entries', ['reference' => 'POS/1/0001']);
        $this->assertSame(1, JournalEntry::query()->count());
    }

    public function test_a_clean_ledger_reports_nothing_to_do(): void
    {
        $session = PosSession::query()->create([
            'reference' => 'POS-S/0001', 'state' => SessionState::Opened,
            'opening_cash' => 0.0, 'opened_at' => now(),
        ]);
        PosOrder::query()->create([
            'reference' => 'POS/1/0001', 'pos_session_id' => $session->id,
            'state' => OrderState::Done->value, 'total' => 20, 'ordered_at' => now(),
        ]);
        JournalEntry::query()->create([
            'number' => 'SALE/2026/0001', 'date' => now()->toDateString(),
            'reference' => 'POS/1/0001', 'state' => 'posted',
        ]);

        $this->artisan('pos:audit-ledger', [
            '--workspace' => $this->mainWorkspaceId(),
            '--purge' => true,
            '--confirm' => true,
        ])->assertExitCode(0);

        $this->assertSame(1, JournalEntry::query()->count());
    }
}
