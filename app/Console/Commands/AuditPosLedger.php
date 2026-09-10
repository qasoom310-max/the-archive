<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Erp\Tenancy\WorkspaceManager;
use App\Models\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Accounting\Models\JournalEntry;
use Modules\Pos\Models\PosOrder;

/**
 * READ-ONLY audit: find journal entries left behind by POS orders that no longer
 * exist ("orphans"), per workspace.
 *
 * Why this exists: until 2026-08-09 the engine's generic `ListView` bulk delete
 * removed POS orders with a query-builder mass delete, which fires no model
 * events and so skipped the ledger cleanup in `PosSaleEraser`. Orders vanished
 * while their `POS/...` (sale) and `DEL/POS/...` (delivery cost) entries stayed
 * posted, so Accounting kept counting revenue for sales that were gone. The
 * delete paths are fixed, but any damage already done is still sitting in the
 * books — this command measures it.
 *
 * It writes NOTHING unless BOTH --purge and --confirm are passed; plain runs and
 * --purge on its own are safe against production at any time.
 *
 * Purging is a deliberate accounting decision, never a default: an orphan is not
 * automatically wrong. The sale really did happen — it is the *order* that was
 * destroyed — so on a live shop these entries are the surviving record of real
 * revenue and deleting them is the error, not the repair. The case --purge exists
 * for is the opposite one: a shop still being set up, wiping startup/test data to
 * start clean. `pos:clear-sales` cannot reach these, because it walks existing
 * orders and an orphan's order is already gone — so without this a "fresh start"
 * silently keeps ghost revenue in the books.
 *
 * Settlement entries ("STL/...") are deliberately not audited: they key on the
 * settlement reference, not the order, so they are never orphaned by this bug.
 */
final class AuditPosLedger extends Command
{
    protected $signature = 'pos:audit-ledger
        {--workspace= : Only audit this workspace id (default: every workspace)}
        {--details : List every orphaned entry, not just the per-workspace totals}
        {--purge : Delete the orphaned entries (dry run unless --confirm is also passed)}
        {--confirm : With --purge, actually delete. THIS REMOVES REVENUE FROM THE BOOKS.}';

    protected $description = 'Report journal entries whose POS order no longer exists (--purge to clear them).';

    public function handle(WorkspaceManager $manager): int
    {
        $wsOption = $this->option('workspace');

        $workspaces = $manager->all()
            ->when(
                is_string($wsOption) && $wsOption !== '',
                fn ($all) => $all->where('id', (int) $wsOption),
            );

        if ($workspaces->isEmpty()) {
            $this->error('No matching workspace.');

            return self::FAILURE;
        }

        $grandCount = 0;
        $grandValue = 0.0;

        foreach ($workspaces as $workspace) {
            /** @var Workspace $workspace */
            $this->newLine();
            $this->line("── {$workspace->name} (#{$workspace->id})" . ($workspace->is_main ? '  [main]' : ''));

            $result = $this->auditWorkspace($manager, $workspace);
            if ($result === null) {
                continue;
            }

            [$count, $value] = $result;
            $grandCount += $count;
            $grandValue += $value;
        }

        $this->newLine();
        if ($grandCount === 0) {
            $this->info('No orphaned POS journal entries found. The ledger matches the orders.');

            return self::SUCCESS;
        }

        $this->warn(sprintf(
            'TOTAL: %d orphaned journal entr%s worth %s across all audited workspaces.',
            $grandCount,
            $grandCount === 1 ? 'y' : 'ies',
            number_format($grandValue, 2),
        ));
        $this->line('These are sales the books still count but whose orders were deleted.');

        if ($this->purging()) {
            $this->info('Deleted — the ledger no longer counts them.');
        } elseif ((bool) $this->option('purge')) {
            $this->newLine();
            $this->warn('DRY RUN — nothing was deleted. Re-run with --purge --confirm to apply.');
            $this->line('Only do that on a shop still being set up: on a live shop these');
            $this->line('entries are the surviving record of real sales.');
        }

        return self::SUCCESS;
    }

    /**
     * True only when the operator asked for deletion AND confirmed it. Every
     * other combination is a report.
     */
    private function purging(): bool
    {
        return (bool) $this->option('purge') && (bool) $this->option('confirm');
    }

    /**
     * @return array{0: int, 1: float}|null  [orphan count, total debit] — null when skipped
     */
    private function auditWorkspace(WorkspaceManager $manager, Workspace $workspace): ?array
    {
        if ($workspace->is_main) {
            return $this->scan();
        }

        $path = $workspace->databasePath();
        if ($path === null || ! is_file($path)) {
            $this->warn('  Database file missing — skipped.');

            return null;
        }

        return $manager->withTenant($path, fn (): ?array => $this->scan());
    }

    /**
     * @return array{0: int, 1: float}|null
     */
    private function scan(): ?array
    {
        if (! Schema::hasTable('journal_entries') || ! Schema::hasTable('pos_orders')) {
            $this->line('  POS or Accounting not installed here — skipped.');

            return null;
        }

        // Every ledger entry a POS *order* can produce: the sale, keyed on the
        // order reference, and the remote-delivery cost keyed "DEL/<reference>".
        $entries = JournalEntry::query()
            ->where(function ($q): void {
                $q->where('reference', 'like', 'POS/%')
                    ->orWhere('reference', 'like', 'DEL/POS/%');
            })
            ->orderBy('date')
            ->get();

        if ($entries->isEmpty()) {
            $this->line('  No POS-derived journal entries.');

            return [0, 0.0];
        }

        // One lookup instead of a query per entry: pull the surviving references
        // and test membership in memory.
        $live = PosOrder::query()->pluck('reference')->flip();

        $rows = [];
        $value = 0.0;
        /** @var list<JournalEntry> $orphans */
        $orphans = [];

        foreach ($entries as $entry) {
            $reference = (string) $entry->reference;
            $orderRef = str_starts_with($reference, 'DEL/') ? substr($reference, 4) : $reference;

            if ($live->has($orderRef)) {
                continue;
            }

            $debit = $entry->totalDebit();
            $value += $debit;
            $orphans[] = $entry;
            $rows[] = [
                $entry->number,
                $reference,
                $entry->date->toDateString(),
                number_format($debit, 2),
            ];
        }

        if ($rows === []) {
            $this->info(sprintf('  OK — all %d POS entries have a live order.', $entries->count()));

            return [0, 0.0];
        }

        $this->warn(sprintf(
            '  %d orphaned entr%s worth %s (of %d POS entries).',
            count($rows),
            count($rows) === 1 ? 'y' : 'ies',
            number_format($value, 2),
            $entries->count(),
        ));

        if ((bool) $this->option('details')) {
            $this->table(['Entry', 'Reference', 'Date', 'Debit'], $rows);
        }

        if ($this->purging()) {
            // One transaction per workspace: a mid-way failure leaves the books
            // as they were rather than half-cleared.
            DB::transaction(static function () use ($orphans): void {
                foreach ($orphans as $entry) {
                    $entry->items()->delete();
                    $entry->delete();
                }
            });

            $this->line(sprintf('  Purged %d entr%s.', count($orphans), count($orphans) === 1 ? 'y' : 'ies'));
        }

        return [count($rows), $value];
    }
}
