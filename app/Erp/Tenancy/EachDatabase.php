<?php

declare(strict_types=1);

namespace App\Erp\Tenancy;

use Closure;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Run a job once against EVERY database — Main first, then each workspace in
 * its own connection.
 *
 * Scheduled work runs on Main's connection, so anything per-database (the daily
 * sales report, the discount expiry sweep) only ever touched Main: a second
 * database never got its 6 AM report, and its lapsed discounts kept showing as
 * active. Backups already looped every database; this is the same loop, shared.
 *
 * Resilient by design: a failure on one database is logged and the rest still
 * run. A database whose file has gone is skipped.
 */
final class EachDatabase
{
    /**
     * @param  Closure(string $label): void  $callback  receives the database's name
     * @return int  how many databases the callback completed on
     */
    public static function run(Closure $callback): int
    {
        $done = 0;

        // Main (the default connection) first — always present.
        if (self::attempt($callback, 'Main')) {
            $done++;
        }

        if (! Schema::hasTable('workspaces')) {
            return $done;
        }

        $manager = app(WorkspaceManager::class);

        foreach ($manager->all() as $workspace) {
            if ($workspace->is_main) {
                continue;
            }

            $path = $workspace->databasePath();

            if ($path === null || ! is_file($path)) {
                continue;
            }

            try {
                $ran = $manager->withTenant(
                    $path,
                    static fn (): bool => self::attempt($callback, (string) $workspace->name),
                );

                if ($ran) {
                    $done++;
                }
            } catch (Throwable $e) {
                Log::error('Scheduled task failed for a workspace', [
                    'workspace' => $workspace->name,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $done;
    }

    /**
     * @param  Closure(string $label): void  $callback
     */
    private static function attempt(Closure $callback, string $label): bool
    {
        try {
            $callback($label);

            return true;
        } catch (Throwable $e) {
            Log::error('Scheduled task failed', ['database' => $label, 'error' => $e->getMessage()]);

            return false;
        }
    }
}
