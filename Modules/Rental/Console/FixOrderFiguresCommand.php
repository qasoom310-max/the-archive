<?php

declare(strict_types=1);

namespace Modules\Rental\Console;

use App\Erp\Tenancy\WorkspaceManager;
use Illuminate\Console\Command;
use Modules\Rental\Support\OrderFigureCorrector;

/**
 * `php artisan rental:fix-order-figures path/to/old-orders.csv --workspace=7 --pretend`
 *
 * Copies the previous system's Amount / VAT / Total / Receipt / Balance /
 * Deposit onto the rental order with the same RA#, for orders the historical
 * import brought over at 0.00. Run with --pretend first and read the list.
 * --sync-active also makes the ERP's active orders match the file (the old
 * system's "Active Orders" export): listed orders become active, active
 * orders missing from it are closed.
 */
final class FixOrderFiguresCommand extends Command
{
    protected $signature = 'rental:fix-order-figures
        {path : The old system\'s orders export (CSV with RA#, Amount, VAT, Total, Receipt, Balance, Deposit)}
        {--workspace= : Workspace id to correct (defaults to the main database)}
        {--pretend : Report what would change, save nothing}
        {--sync-active : Also make the active orders match the file}';

    protected $description = 'Restore rental order amounts, totals and balances from the previous system\'s export.';

    public function handle(OrderFigureCorrector $corrector, WorkspaceManager $workspaces): int
    {
        $path = (string) $this->argument('path');
        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $option = $this->option('workspace');
        $workspaceId = is_string($option) && $option !== '' ? (int) $option : null;
        if ($workspaceId !== null) {
            $workspace = $workspaces->findAny($workspaceId);
            if ($workspace === null || $workspace->trashed()) {
                $this->error("Workspace {$workspaceId} not found.");

                return self::FAILURE;
            }
            $this->info("Workspace {$workspaceId}: {$workspace->name}");
        }

        $pretend = (bool) $this->option('pretend');
        $sync = (bool) $this->option('sync-active');

        /** @var array{rows: int, updated: int, unchanged: int, missing: list<string>, changes: list<string>, not_in_file: list<string>, activated: int, closed: int} $r */
        $r = $workspaces->runFor($workspaceId, fn (): array => $corrector->correct($path, $pretend, $sync));

        foreach ($r['changes'] as $line) {
            $this->line($line);
        }
        if ($r['missing'] !== []) {
            $this->warn('Not found in the ERP: ' . implode(', ', $r['missing']));
        }
        if ($sync) {
            $this->line("Made active: {$r['activated']}. Closed (active here, not in the file): {$r['closed']}" . ($r['not_in_file'] !== [] ? ' — ' . implode(', ', $r['not_in_file']) : ''));
        }

        $this->info(($pretend ? '[DRY RUN — nothing saved] ' : '') . "Rows {$r['rows']}, corrected {$r['updated']}, already right {$r['unchanged']}, not found " . count($r['missing']) . '.');

        return self::SUCCESS;
    }
}
