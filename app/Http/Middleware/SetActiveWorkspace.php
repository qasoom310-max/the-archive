<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Erp\Tenancy\WorkspaceManager;
use App\Models\User;
use App\Models\Workspace;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Routes the request to the active workspace's database (from the
 * {@see Workspace::COOKIE} cookie). Runs in the web group AFTER the session
 * starts (so the logged-in identity is known) and BEFORE the `auth` route
 * middleware (so it can rebind auth to the tenant DB).
 *
 * Main is a strict, zero-cost no-op: with no workspace cookie the request
 * returns immediately — no DB query, no dependency on the `workspaces` table
 * — so the live app is byte-for-byte unchanged and safe even mid-deploy
 * before the table exists. Any failure in the tenant path is swallowed and
 * falls back to Main rather than ever breaking a request.
 *
 * For a tenant: swap the default connection to its SQLite file and rebind the
 * request's auth user to the matching account in that DB (matched by email —
 * every Main admin is copied into each workspace at provisioning). The
 * persisted session login id is never touched, so switching back to Main
 * restores the original identity cleanly.
 */
final class SetActiveWorkspace
{
    public function handle(Request $request, Closure $next): Response
    {
        $cookie = $request->cookie(Workspace::COOKIE);

        // No cookie → Main. Return before touching the DB at all.
        if (! is_string($cookie) || ! ctype_digit($cookie)) {
            return $next($request);
        }

        try {
            if (! Schema::hasTable('workspaces')) {
                return $next($request);
            }

            $workspace = Workspace::query()->find((int) $cookie);

            if ($workspace === null || $workspace->is_main) {
                return $next($request);
            }

            // Capture the logged-in identity from Main BEFORE the swap.
            $email = Auth::check() ? Auth::user()?->email : null;

            $previous = DB::getDefaultConnection();
            app(WorkspaceManager::class)->activate($workspace);

            if ($email !== null) {
                $tenantUser = User::query()->where('email', $email)->first();

                if ($tenantUser !== null) {
                    Auth::setUser($tenantUser);
                } else {
                    // This admin has no account in the workspace — fail safe
                    // back to Main rather than locking them out.
                    config(['database.default' => $previous]);
                    DB::setDefaultConnection($previous);
                }
            }
        } catch (Throwable $e) {
            // The workspace layer must never break a request — log and serve
            // Main. (Main users never reach here; only cookie-bearing admins.)
            report($e);
        }

        return $next($request);
    }
}
