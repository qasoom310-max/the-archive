<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Erp\Tenancy\WorkspaceManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * Apply core migrations to every tenant workspace database.
 *
 * Tenant SQLite files are fully migrated at provision time, but a CORE
 * migration added later only auto-applies to Main (the deploy runs
 * `migrate --force` on the default connection). This command backfills those
 * later migrations into each existing workspace so features like the activity
 * log work there too. Idempotent (`migrate --force` skips applied migrations);
 * resilient (a failure on one workspace never aborts the rest or the deploy).
 */
final class MigrateWorkspaces extends Command
{
    protected $signature = 'workspaces:migrate';

    protected $description = 'Run core migrations against every tenant workspace database.';

    public function handle(WorkspaceManager $manager): int
    {
        foreach ($manager->all() as $workspace) {
            if ($workspace->is_main) {
                continue; // Main is migrated by the normal `migrate` step.
            }

            $path = $workspace->databasePath();
            if ($path === null || ! is_file($path)) {
                $this->warn("Skipping {$workspace->name}: database file missing.");

                continue;
            }

            try {
                $manager->withTenant($path, function (): void {
                    Artisan::call('migrate', ['--force' => true]);
                });
                $this->info("Migrated workspace: {$workspace->name}");
            } catch (Throwable $e) {
                // Never abort the deploy because one workspace failed.
                $this->error("Failed migrating {$workspace->name}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
