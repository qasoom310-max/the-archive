<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Erp\Modules\ModuleManager;
use App\Erp\Tenancy\WorkspaceManager;
use Illuminate\Console\Command;
use Throwable;

/**
 * Backfill module installation into every existing tenant workspace.
 *
 * A workspace installs only the modules that EXISTED when it was provisioned
 * ({@see WorkspaceManager::provision()} loops the modules discovered at that
 * moment). A module added LATER — Rental, Limousine — therefore never lands in
 * an older tenant's `ir_module` table, even though:
 *  - its migrations DO reach the tenant ({@see MigrateWorkspaces}), and
 *  - it IS installed on Main (the deploy's `module:install`).
 * The app bar reads the CURRENT workspace's installed modules, so the app stays
 * invisible there until the module row exists — no business-type setting can
 * surface it.
 *
 * This command installs every discovered module (or one named module) into each
 * existing workspace. `ModuleManager::install()` is idempotent — already-
 * installed modules return early — so it is safe to run on every deploy.
 * Resilient: a failure on one workspace or module never aborts the rest.
 *
 * It then RESYNCS each module's registry into that workspace, which is a
 * separate job from installing. Views (`ir_ui_view`) are stored per database,
 * and `install()`'s early return means an already-installed module never
 * refreshes them — so every edit to a model's `irModelDefinition()` reached Main
 * (the deploy runs `module:resync` there) but NEVER reached a tenant. Kaleem was
 * rendering the layout frozen at provisioning time: a form field made read-only
 * in code stayed editable there, and new fields never appeared. Resyncing here
 * is what makes a layout change actually ship to every database.
 */
final class InstallModulesInWorkspaces extends Command
{
    protected $signature = 'workspaces:install-modules {name? : Limit to one module; omit to install all discovered}';

    protected $description = 'Install + resync every (or one) module into each existing tenant workspace database (idempotent).';

    public function handle(WorkspaceManager $manager, ModuleManager $modules): int
    {
        $only = $this->argument('name');

        /** @var list<string> $targets */
        $targets = is_string($only) && $only !== ''
            ? [$only]
            : array_keys($modules->discover());

        foreach ($manager->all() as $workspace) {
            if ($workspace->is_main) {
                continue; // Main is installed by the deploy's `module:install` step.
            }

            $path = $workspace->databasePath();
            if ($path === null || ! is_file($path)) {
                $this->warn("Skipping {$workspace->name}: database file missing.");

                continue;
            }

            try {
                $manager->withTenant($path, function () use ($modules, $targets, $workspace): void {
                    foreach ($targets as $module) {
                        try {
                            $modules->install($module);

                            // Separate step, and the one that matters for an
                            // ALREADY-installed module: install() returned early
                            // above, so without this the tenant keeps the stored
                            // views it was provisioned with and no layout change
                            // ever reaches it. No-ops when the module isn't
                            // installed here.
                            $modules->resyncRegistry($module);
                        } catch (Throwable $e) {
                            $this->warn("  {$workspace->name}: {$module} → {$e->getMessage()}");
                        }
                    }
                });
                $this->info("Installed + resynced modules into workspace: {$workspace->name}");
            } catch (Throwable $e) {
                // Never abort the deploy because one workspace failed.
                $this->error("Failed on {$workspace->name}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
