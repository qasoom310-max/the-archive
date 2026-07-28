<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Erp\Tenancy\WorkspaceManager;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;

/**
 * Post-login database chooser. Signing in authenticates against Main (the
 * identity store) and then lands HERE, so the user picks which business
 * database to enter every time — instead of being silently dropped into
 * whichever one a stale year-long cookie happened to remember.
 *
 * Plain GET controller (not Livewire) on purpose: the workspace cookie is set on
 * a redirect, and a Set-Cookie only reliably rides a real HTTP redirect — the
 * same reason {@see SwitchWorkspaceController} is a GET.
 *
 * The picker is shown AFTER authentication, never on the public login form, so
 * the list of the owner's businesses is not exposed to anonymous visitors.
 * Users who have only one place to go — a locked single-business staff member,
 * or anyone with a single database — skip the picker entirely.
 */
final class ChooseWorkspaceController
{
    /** GET /choose — pick a database, or skip straight in when there's no choice. */
    public function index(WorkspaceManager $manager): View|RedirectResponse
    {
        $user = $this->identity();
        if ($user === null) {
            return redirect('/');
        }

        $accessible = $manager->accessibleFor($user);

        // Locked user, or a single database → nothing to choose, go right in.
        if ($accessible->count() <= 1) {
            $only = $accessible->first();

            return $only instanceof Workspace ? $this->enterWorkspace($only) : redirect('/');
        }

        $cookie = request()->cookie(Workspace::COOKIE);

        return view('workspaces.choose', [
            'workspaces' => $accessible,
            'currentId' => is_string($cookie) && ctype_digit($cookie) ? (int) $cookie : null,
            'userName' => $user->name,
        ]);
    }

    /** GET /choose/{workspace} — enter the chosen database. */
    public function enter(int $workspace, WorkspaceManager $manager): RedirectResponse
    {
        $user = $this->identity();
        abort_if($user === null, 403);

        $target = $manager->find($workspace);
        abort_if($target === null, 404);

        // Never let a user cookie themselves into a database they have no
        // account in (a hand-typed /choose/{id}).
        abort_unless(
            $manager->accessibleFor($user)->contains(static fn (Workspace $w): bool => $w->id === $target->id),
            403,
        );

        return $this->enterWorkspace($target);
    }

    private function enterWorkspace(Workspace $workspace): RedirectResponse
    {
        // One year; the active workspace is a per-browser choice that persists
        // for in-session navigation. Each fresh login funnels back through the
        // picker regardless, so this never causes a silent auto-jump.
        Cookie::queue(Workspace::COOKIE, (string) $workspace->id, 60 * 24 * 365);

        return redirect('/');
    }

    /**
     * The signed-in identity resolved on the MAIN database. Inside a workspace
     * `Auth::user()` is a tenant MIRROR whose home_workspace_id / is_admin can
     * differ, so access must be judged on the canonical Main row — matched by
     * email, the stable key across every database.
     */
    private function identity(): ?User
    {
        $email = Auth::user()?->email;
        if (! is_string($email) || $email === '') {
            return null;
        }

        return User::on(Workspace::$landlordConnection)->where('email', $email)->first();
    }
}
