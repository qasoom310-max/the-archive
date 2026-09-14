<?php

declare(strict_types=1);

namespace Modules\Limousine\Console;

use App\Erp\Backup\DatabaseBackup;
use App\Erp\Tenancy\WorkspaceManager;
use Illuminate\Console\Command;
use Modules\Limousine\Support\LegacyQuotationImporter;

/**
 * `php artisan limo:import-legacy-quotations limo-quotation.csv --workspace=7`
 *
 * Adds quotations from the previous system's own quotations export that this
 * database does not have yet, keeping their original quote numbers in
 * `reference`. Meant to be re-run periodically against a fresher export — a
 * row already on file is never touched. Run it with `--pretend` first: it
 * reports every row (NEW / EXISTS / SKIP) and saves nothing.
 */
final class ImportLegacyQuotations extends Command
{
    protected $signature = 'limo:import-legacy-quotations
        {path : CSV exported from the old system}
        {--workspace= : Workspace id to import into (required)}
        {--pretend : Report what would happen, save nothing}';

    protected $description = 'Import the old system\'s limousine quotations that are not in the ERP yet, keeping their original quote numbers.';

    public function handle(LegacyQuotationImporter $importer, WorkspaceManager $workspaces, DatabaseBackup $backups): int
    {
        $path = (string) $this->argument('path');
        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        // A bulk import into the wrong database is the disaster case, so the
        // workspace is required and an unknown id fails rather than falling
        // through to Main.
        $workspaceId = (int) $this->option('workspace');
        $workspace = $workspaceId > 0 ? $workspaces->find($workspaceId) : null;
        if ($workspace === null) {
            $this->error('Pass --workspace=<id> of an existing workspace.');

            return self::FAILURE;
        }

        $pretend = (bool) $this->option('pretend');
        $this->info(($pretend ? '[DRY RUN] ' : '')."Workspace {$workspace->id}: {$workspace->name}");

        $result = $workspaces->runFor($workspace->id, function () use ($importer, $backups, $path, $pretend): array {
            if (! $pretend) {
                // Restorable from Activity Log → Backups if anything looks wrong.
                $this->info('Backup taken: '.basename($backups->snapshot()));
            }

            return $importer->import($path, $pretend);
        });

        foreach ($result['lines'] as $line) {
            $this->line($line);
        }

        $this->info(($pretend ? 'Would import ' : 'Imported ')."{$result['imported']}, skipped {$result['skipped']}.");

        return self::SUCCESS;
    }
}
