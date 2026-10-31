<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Erp\Settings\CompanyTimezone;
use App\Erp\Tenancy\WorkspaceManager;
use App\Models\User;
use App\Models\Workspace;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

/**
 * The staff app's equivalent of {@see SetActiveWorkspace}: runs a token-signed
 * request inside the person's business database.
 *
 * The token identifies the MAIN account (every login lives on Main). The
 * database is the one the account is locked to; else the `X-Workspace` the app
 * sends, if this person may open it; else the first business they belong to
 * when Main holds nothing for them. Auth is then rebound to the same person in
 * that database, matched by email — or the request stays on Main, never
 * resolving one database's ids against another's.
 *
 * A paused account is refused here on every request, like the website's
 * EnsureUserIsNotPaused, in whichever database it is paused.
 */
final class AppWorkspace
{
    public const HEADER = 'X-Workspace';

    /** Request attribute: the Main account the token belongs to. */
    public const LOGIN = 'app.login';

    /** Request attribute: the business database entered (absent on Main). */
    public const WORKSPACE = 'app.workspace';

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            abort(401);
        }

        if ($user->isPaused()) {
            abort(403, __('Your account has been paused. Contact your administrator.'));
        }

        // The Main account, for what only it knows (the databases it may open).
        $request->attributes->set(self::LOGIN, $user);

        $before = [
            DB::getDefaultConnection(),
            config('database.connections.tenant.database'),
            config('cache.prefix'),
            CompanyTimezone::current(),
        ];

        try {
            $workspace = Schema::hasTable('workspaces') ? $this->target($request, $user) : null;

            if ($workspace !== null && ! $workspace->is_main && $this->enter($workspace, $user)) {
                $request->attributes->set(self::WORKSPACE, $workspace);

                $here = Auth::user();
                if ($here instanceof User && $here->isPaused()) {
                    abort(403, __('Your account has been paused. Contact your administrator.'));
                }
            }

            return $next($request);
        } finally {
            // Once the response is made, put Main and the Main sign-in back. Each
            // request is its own process in production anyway, but nothing — a
            // test, a long-running worker — should inherit this business's
            // database or its copy of the account.
            [$connection, $path, $prefix, $timezone] = $before;
            config([
                'database.default' => $connection,
                'database.connections.tenant.database' => $path,
                'cache.prefix' => $prefix,
            ]);
            DB::setDefaultConnection($connection);
            DB::purge('tenant');
            CompanyTimezone::restore($timezone);
            Auth::setUser($user);
        }
    }

    private function target(Request $request, User $user): ?Workspace
    {
        $manager = app(WorkspaceManager::class);

        $locked = $user->homeWorkspaceId();
        if ($locked !== null) {
            return Workspace::query()->find($locked);
        }

        $accessible = $manager->accessibleFor($user);

        $asked = $request->header(self::HEADER);
        if (is_string($asked) && ctype_digit($asked)) {
            $chosen = $accessible->first(static fn (Workspace $w): bool => (int) $w->id === (int) $asked);
            if ($chosen instanceof Workspace) {
                return $chosen;
            }
        }

        // Nothing asked for: an admin, or staff with apps on Main, work on Main;
        // staff of one business work in it.
        if ($user->isAdmin() || $user->hasGrantedAccess()) {
            return null;
        }

        $own = $accessible->first(static fn (Workspace $w): bool => ! $w->is_main);

        return $own instanceof Workspace ? $own : null;
    }

    /** False (and still on Main) when the person has no account in it. */
    private function enter(Workspace $workspace, User $user): bool
    {
        $email = $user->email;
        $previous = DB::getDefaultConnection();
        $previousCachePrefix = config('cache.prefix');

        app(WorkspaceManager::class)->activate($workspace);

        // activate() stays on Main when the database file is missing; never
        // then pretend the request is inside the workspace.
        if (DB::getDefaultConnection() !== 'tenant') {
            return false;
        }

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
