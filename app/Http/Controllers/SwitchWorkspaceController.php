<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Erp\Tenancy\WorkspaceManager;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;

/**
 * Switch the active workspace by setting the {@see Workspace::COOKIE} cookie,
 * then full-page redirect home so the new database connection takes effect
 * cleanly. Admin-only. A plain GET endpoint (not a Livewire action) so the
 * Set-Cookie reliably rides the redirect.
 */
final class SwitchWorkspaceController
{
    public function __invoke(int $workspace): RedirectResponse
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->isAdmin(), 403);

        $target = app(WorkspaceManager::class)->find($workspace);
        abort_if($target === null, 404);

        // One year; the active workspace is a per-browser preference.
        Cookie::queue(Workspace::COOKIE, (string) $target->id, 60 * 24 * 365);

        return redirect('/');
    }
}
