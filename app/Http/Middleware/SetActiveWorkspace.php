<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Erp\Tenancy\WorkspaceManager;
use App\Models\User;
use App\Models\Workspace;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
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
 * Main is cheap: with no workspace cookie an admin returns at once and other
 * users pay one check per session. Someone with nothing to do on Main (staff
 * of one business) is sent to their own database instead. Safe mid-deploy
 * before the `workspaces` table exists. Any failure is swallowed and
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
        // Guests ALWAYS operate on Main — the canonical identity store.
        //
        // Logging in must authenticate against Main so the session stores a
        // *Main* user id. Each tenant database has its own, unrelated
        // auto-increment ids; a tenant-local id read back on Main resolves to
        // a DIFFERENT person. If we let a not-yet-authenticated request with a
        // stale workspace cookie swap to the tenant, the login form would
        // authenticate against the tenant and persist a tenant id — then the
        // next request reads that id on Main and silently logs the visitor in
        // as whoever owns it there (the "log in as qassim, land as ramadan"
        // bug). Short-circuiting guests here keeps login on Main and makes a
        // leftover cookie harmless until an admin is actually signed in.
        if (! Auth::check()) {
            return $next($request);
        }

        $user = Auth::user();

        // A LOCKED user is always routed to their home workspace — the cookie
        // is ignored, so a Kaleem-only admin can never reach another business's
        // database (nor Main). An unrestricted user follows the cookie as usual.
        $lockedId = $user instanceof User ? $user->homeWorkspaceId() : null;

        if ($lockedId !== null) {
            $targetId = $lockedId;
        } else {
            $cookie = $request->cookie(Workspace::COOKIE);
            $targetId = is_string($cookie) && ctype_digit($cookie) ? (int) $cookie : null;
        }

        try {
            if ($targetId !== null && Schema::hasTable('workspaces')) {
                $workspace = Workspace::query()->find($targetId);

                if ($workspace !== null && ! $workspace->is_main && $this->enter($workspace)) {
                    return $next($request);
                }
            }

            // Still on Main. Fine for anyone who can work here; but someone
            // whose Main account is only a login (staff of one business) would
            // get 403 on every page. That happens when the browser lost its
            // workspace cookie, or carries another business's. Send them to
            // their own database instead, and remember it in the cookie.
            if ($user instanceof User && $lockedId === null && ! $this->mayWorkOnMain($user)
                && Schema::hasTable('workspaces')) {
                $own = app(WorkspaceManager::class)->accessibleFor($user)
                    ->first(static fn (Workspace $w): bool => ! $w->is_main);

                if ($own instanceof Workspace && $this->enter($own)) {
                    Cookie::queue(Workspace::COOKIE, (string) $own->id, 60 * 24 * 365);
                }
            }
        } catch (Throwable $e) {
            // The workspace layer must never break a request — log and serve
            // Main.
            report($e);
        }

        return $next($request);
    }

    /**
     * Admins, and staff with apps on Main, belong there. Checked once per
     * session when true (cashiers on Main pay nothing per request after it).
     */
    private function mayWorkOnMain(User $user): bool
    {
        if ($user->isAdmin() || session('erp.main_access') === true) {
            return true;
        }

        if ($user->hasGrantedAccess()) {
            session(['erp.main_access' => true]);

            return true;
        }

        return false;
    }

    /**
     * Switch this request to a workspace and rebind auth to the same person
     * there. False (and still on Main) when they have no account in it.
     */
    private function enter(Workspace $workspace): bool
    {
        // Capture the logged-in identity from Main BEFORE the swap.
        $email = Auth::user()?->email;

        $previous = DB::getDefaultConnection();
        $previousCachePrefix = config('cache.prefix');
        app(WorkspaceManager::class)->activate($workspace);

        // Rebind to the SAME identity inside the tenant, matched by email
        // (how provisioning aligns accounts across databases). We must
        // ALWAYS either positively rebind or revert to Main — never leave
        // the request on the tenant with the guard free to resolve Main's
        // session id against the tenant's unrelated ids. So a user with no
        // email to match on, or no account in this workspace, fails safe
        // back to Main rather than impersonating a tenant row.
        $tenantUser = (is_string($email) && $email !== '')
            ? User::query()->where('email', $email)->first()
            : null;

        if ($tenantUser !== null) {
            Auth::setUser($tenantUser);

            return true;
        }

        config(['database.default' => $previous, 'cache.prefix' => $previousCachePrefix]);
        DB::setDefaultConnection($previous);

        return false;
    }
}
