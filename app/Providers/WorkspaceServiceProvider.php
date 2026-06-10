<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Workspace;
use Illuminate\Support\ServiceProvider;

/**
 * Multi-database ("workspaces") wiring. Each workspace is a separate SQLite
 * file; the **default** DB connection is swapped to the active workspace
 * per-request by {@see \App\Http\Middleware\SetActiveWorkspace}.
 *
 * To keep the live system safe when that swap happens, the pieces that must
 * never move are pinned to the BOOT-TIME default connection by NAME (the
 * "Main" workspace = today's data — `mysql` on prod, `sqlite` locally):
 *   - the {@see Workspace} registry model (so the list is always read from Main),
 *   - **sessions** (so a tenant swap never logs anyone out),
 *   - the database **queue** (so the WhatsApp/cron worker keeps draining Main).
 * Pinning by name (not a cloned connection) keeps the SAME underlying
 * connection/schema — important for the in-memory SQLite used in tests.
 *
 * With no workspace cookie set NOTHING changes: the default stays the Main
 * connection, so Main is byte-for-byte the current app.
 */
final class WorkspaceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $default = (string) config('database.default');

        // The registry + login state + queue always live on the Main DB.
        Workspace::$landlordConnection = $default;
        config(['session.connection' => $default]);
        config(['queue.connections.database.connection' => $default]);

        // Reusable tenant SQLite connection; its `database` path is set
        // per-request once a workspace is active (by the middleware /
        // provisioner). Inactive default path is never actually opened.
        config(['database.connections.tenant' => [
            'driver' => 'sqlite',
            'url' => null,
            'database' => storage_path('app/workspaces/__inactive__.sqlite'),
            'prefix' => '',
            'foreign_key_constraints' => true,
            'busy_timeout' => null,
            'journal_mode' => null,
            'synchronous' => null,
        ]]);
    }
}
