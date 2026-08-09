<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Erp\Tenancy\WorkspaceManager;
use App\Models\Workspace;
use Illuminate\Console\Command;
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
 * It writes NOTHING. It is safe to run against production at any time. Repair is
 * a separate, deliberate decision: an orphan is not automatically wrong (the sale
 * really did happen — it is the *order* that was destroyed), so deleting these
 * entries could just as easily be the accounting error as the fix.
 *
 * Settlement entries ("STL/...") are deliberately not audited: they key on the
 * settlement reference, not the order, so they are never orphaned by this bug.
 */
final class AuditPosLedger extends Command
{
    protected $signature = 'pos:audit-ledger
        {--workspace= : Only audit this workspace id (default: every workspace)}
        {--details : List every orphaned entry, not just the per-workspace totals}';

    protected $description = 'READ-ONLY: report journal entries whose POS order no longer exists.';

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

        return self::SUCCESS;
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

        foreach ($entries as $entry) {
            $reference = (string) $entry->reference;
            $orderRef = str_starts_with($reference, 'DEL/') ? substr($reference, 4) : $reference;

            if ($live->has($orderRef)) {
                continue;
            }

            $debit = $entry->totalDebit();
            $value += $debit;
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

        return [count($rows), $value];
    }
}
