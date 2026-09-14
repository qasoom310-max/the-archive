<?php

declare(strict_types=1);

namespace Modules\Limousine\Console;

use App\Erp\Backup\DatabaseBackup;
use App\Erp\Tenancy\WorkspaceManager;
use Illuminate\Console\Command;
use Modules\Limousine\Support\LegacyBookingImporter;

/**
 * `php artisan limo:import-legacy-bookings limo-active-booking.csv --workspace=7 --kind=active`
 *
 * Adds the bookings from one of the previous system's booking lists that this
 * database does not have yet, under their original booking numbers. Run it
 * with `--pretend` first: it reports every row (NEW / EXISTS / CLASH / TWIN)
 * and saves nothing.
 */
final class ImportLegacyBookings extends Command
{
    protected $signature = 'limo:import-legacy-bookings
        {path : CSV exported from the old system}
        {--workspace= : Workspace id to import into (required)}
        {--kind= : active|queue|cancelled|closed — which old list the file came from}
        {--pretend : Report what would happen, save nothing}';

    protected $description = 'Import the old system\'s limousine bookings that are not in the ERP yet, keeping their booking numbers.';

    public function handle(LegacyBookingImporter $importer, WorkspaceManager $workspaces, DatabaseBackup $backups): int
    {
        $path = (string) $this->argument('path');
        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $kind = strtolower((string) $this->option('kind'));
        $status = LegacyBookingImporter::KIND_STATUS[$kind] ?? null;
        if ($status === null) {
            $this->error('Pass --kind='.implode('|', array_keys(LegacyBookingImporter::KIND_STATUS)).'.');

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
        $this->info(($pretend ? '[DRY RUN] ' : '')."Workspace {$workspace->id}: {$workspace->name} — {$kind} → status {$status}");

        $result = $workspaces->runFor($workspace->id, function () use ($importer, $backups, $path, $status, $pretend): array {
            if (! $pretend) {
                // Restorable from Activity Log → Backups if anything looks wrong.
                $this->info('Backup taken: '.basename($backups->snapshot()));
            }

            return $importer->import($path, $status, $pretend);
        });

        foreach ($result['lines'] as $line) {
            $this->line($line);
        }

        $this->info(($pretend ? 'Would import ' : 'Imported ')."{$result['imported']}, skipped {$result['skipped']}.");

        return self::SUCCESS;
    }
}
