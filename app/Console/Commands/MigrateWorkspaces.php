<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Erp\Enums\ModuleState;
use App\Erp\Modules\ModuleManager;
use App\Erp\Tenancy\WorkspaceManager;
use App\Models\Ir\IrModule;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * Apply later migrations to every tenant workspace database.
 *
 * Tenant SQLite files are fully migrated at provision time, but migrations
 * added LATER only auto-apply to Main on deploy:
 *  - CORE migrations run on the default connection (`migrate --force`);
 *  - MODULE migrations run per `--path` against Main only.
 * Neither reaches existing tenants, so a tenant's schema freezes at whatever
 * existed when it was provisioned — e.g. POS gains `pos_x`/`pos_y` or a
 * translatable floor name and the tenant 500s opening those screens.
 *
 * This command backfills BOTH into each existing workspace: core migrations,
 * then every installed module's migrations + an arch re-sync (so later
 * `irModelDefinition()` tweaks like translatable pills land too). Idempotent
 * (`migrate --force` skips applied migrations); resilient (a failure on one
 * workspace or module never aborts the rest or the deploy).
 */
final class MigrateWorkspaces extends Command
{
    protected $signature = 'workspaces:migrate';

    protected $description = 'Run core + installed-module migrations against every tenant workspace database.';

    public function handle(WorkspaceManager $manager, ModuleManager $modules): int
    {
        foreach ($manager->all() as $workspace) {
            if ($workspace->is_main) {
                continue; // Main is migrated by the normal `migrate` + per-module steps.
            }

            $path = $workspace->databasePath();
            if ($path === null || ! is_file($path)) {
                $this->warn("Skipping {$workspace->name}: database file missing.");

                continue;
            }

            try {
                $manager->withTenant($path, function () use ($modules): void {
                    Artisan::call('migrate', ['--force' => true]); // core
                    $this->migrateInstalledModules($modules);
                });
                $this->info("Migrated workspace: {$workspace->name}");
            } catch (Throwable $e) {
                // Never abort the deploy because one workspace failed.
                $this->error("Failed migrating {$workspace->name}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * Run the migrations of every module installed in the CURRENT (tenant)
     * connection, then re-reflect its registry. Driven by the tenant's own
     * `ir_module` table so we only touch modules it actually has.
     */
    private function migrateInstalledModules(ModuleManager $modules): void
    {
        $installed = IrModule::query()
            ->where('state', ModuleState::Installed->value)
            ->pluck('name')
            ->all();

        foreach ($installed as $slug) {
            $manifest = $modules->find((string) $slug);
            if ($manifest === null) {
                continue;
            }

            $migrations = $manifest->migrationsPath();
            if (! is_dir($migrations)) {
                continue;
            }

            Artisan::call('migrate', [
                '--path' => $migrations,
                '--realpath' => true,
                '--force' => true,
            ]);

            // Re-reflect models/fields/views so later irModelDefinition()
            // changes (translatable flags, new columns) reach the tenant too.
            try {
                $modules->resyncRegistry((string) $slug);
            } catch (Throwable $e) {
                $this->warn("Resync {$slug} failed: {$e->getMessage()}");
            }
        }
    }
}
