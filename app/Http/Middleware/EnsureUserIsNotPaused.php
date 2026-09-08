<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Force-logs-out a paused user on their very next request, in every database.
 *
 * An admin's "Pause" action already kills the paused user's sessions directly
 * (see {@see \App\Erp\Security\SessionKiller}), but that is best-effort (it
 * depends on the session driver being database-backed). This middleware is the
 * authoritative guard: it re-checks `is_paused` on the CURRENTLY resolved user
 * (which, inside a workspace, is already rebound by
 * {@see SetActiveWorkspace} to that database's own row) on every request, so a
 * paused account can never keep working — whether the session survived, or a
 * "remember me" cookie silently re-authenticated them.
 *
 * Runs in the `web` middleware group AFTER {@see SetActiveWorkspace} (so the
 * per-database `is_paused` flag being checked is the right one) and does
 * nothing for guests.
 *
 * Aborting with 419 (rather than a manual redirect) reuses the exact "session
 * expired" handling the app already has: a Livewire AJAX request treats a 419
 * response as an expired session and reloads (this app silently, via the
 * `window.confirm` override in the master layout); a plain page load renders
 * `errors/419.blade.php`, which reloads the current URL via JS. Either way the
 * reload lands on `/login` because `Auth::logout()` already ran.
 */
final class EnsureUserIsNotPaused
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user instanceof User && $user->isPaused()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            abort(419);
        }

        return $next($request);
    }
}
