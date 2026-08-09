<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Erp\Backup\DatabaseBackup;
use App\Erp\Tenancy\WorkspaceManager;
use Illuminate\Console\Command;
use Throwable;

/**
 * Daily whole-database snapshot for EVERY database (Main + each tenant
 * workspace), then prune anything past the retention window — the in-app
 * "Hostinger backup". Each database is snapshotted in its own connection, so
 * tenants get their own file set. Resilient: a failure on one database never
 * aborts the rest.
 */
final class BackupDatabases extends Command
{
    protected $signature = 'backups:run';

    protected $description = 'Snapshot every database (Main + tenants) and prune snapshots past the retention window.';

    public function handle(WorkspaceManager $manager, DatabaseBackup $backup): int
    {
        // Main (the default connection) first.
        $this->runFor($backup, 'Main');

        // Then every tenant workspace in its own connection.
        foreach ($manager->all() as $workspace) {
            if ($workspace->is_main) {
                continue;
            }

            $path = $workspace->databasePath();
            if ($path === null || ! is_file($path)) {
                continue;
            }

            try {
                $manager->withTenant($path, function () use ($backup, $workspace): void {
                    $this->runFor($backup, $workspace->name);
                });
            } catch (Throwable $e) {
                $this->error("Backup failed for {$workspace->name}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }

    private function runFor(DatabaseBackup $backup, string $label): void
    {
        try {
            $backup->snapshot();
            $removed = $backup->purge();
            $this->info("Backed up {$label}" . ($removed > 0 ? " (pruned {$removed} old)" : ''));
        } catch (Throwable $e) {
            $this->error("Backup failed for {$label}: {$e->getMessage()}");
        }
    }
}
