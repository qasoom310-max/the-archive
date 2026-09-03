<?php

declare(strict_types=1);

namespace Modules\Rental\Console;

use App\Erp\Tenancy\WorkspaceManager;
use Illuminate\Console\Command;
use Modules\Rental\Support\CustomerImporter;

/**
 * Backend customer import: `php artisan rental:import-customers path/to/file.csv`.
 * Reads a customer CSV (Name, Type, CPR / ID, Phone, E-mail, Country, CR Number,
 * Contact Person, Address) into the shared rental customer store, enriching
 * matched customers' blank fields and skipping true duplicates. Safe to re-run.
 *
 * Pass `--workspace=<id>` to run against a tenant database instead of Main.
 */
final class ImportCustomersCommand extends Command
{
    protected $signature = 'rental:import-customers {path : Path to the customer CSV} {--workspace= : Workspace id to import into (defaults to the main database)}';

    protected $description = 'Import rental customers from a CSV (matches duplicates by CPR/CR then phone, filling in their blank fields).';

    public function handle(CustomerImporter $importer, WorkspaceManager $workspaces): int
    {
        $path = (string) $this->argument('path');

        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $workspaceOption = $this->option('workspace');
        $workspaceId = is_string($workspaceOption) && $workspaceOption !== '' ? (int) $workspaceOption : null;

        // A mistyped workspace id must FAIL, not silently fall through to the
        // main database (runFor's lenient fallback is wrong for a bulk import).
        if ($workspaceId !== null) {
            $workspace = $workspaces->findAny($workspaceId);

            if ($workspace === null || $workspace->trashed()) {
                $this->error("Workspace {$workspaceId} not found.");

                return self::FAILURE;
            }

            $this->info("Importing into workspace {$workspaceId}: {$workspace->name}");
        }

        /** @var array{imported: int, updated: int, skipped: int} $result */
        $result = $workspaces->runFor($workspaceId, fn (): array => $importer->import($path));

        $this->info("Imported {$result['imported']} customers, updated {$result['updated']}, skipped {$result['skipped']} duplicates.");

        return self::SUCCESS;
    }
}
