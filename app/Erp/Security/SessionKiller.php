<?php

declare(strict_types=1);

namespace App\Erp\Security;

use App\Erp\Tenancy\WorkspaceManager;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Instantly kills every active session for a user being paused.
 *
 * Sessions always live on the LANDLORD (Main) database — see
 * {@see \App\Providers\WorkspaceServiceProvider}, which pins `session.connection`
 * to the boot-time default so a tenant swap never logs anyone out. That also
 * means the row to delete is keyed by the MAIN copy of this account (matched
 * by email), never the tenant-local id a workspace-scoped user is edited under.
 *
 * Best-effort only: this is defence-in-depth for an immediate kick. The
 * authoritative guard is {@see \App\Http\Middleware\EnsureUserIsNotPaused},
 * which force-logs-out a paused user on their very next request regardless of
 * session driver — so a failure here (e.g. a non-database session driver)
 * never leaves a paused account able to keep working.
 */
final class SessionKiller
{
    public function killFor(User $user): void
    {
        try {
            app(WorkspaceManager::class)->withMain(function () use ($user): void {
                if (! Schema::hasTable('sessions')) {
                    return;
                }

                $email = $user->email;
                $mainId = $user->getKey();

                if (is_string($email) && $email !== '') {
                    $main = User::query()->where('email', $email)->first();
                    if ($main !== null) {
                        $mainId = $main->getKey();

                        // Rotate the remember-me token too, so a browser holding
                        // that cookie can't silently re-authenticate once the
                        // session row is gone.
                        $main->forceFill(['remember_token' => Str::random(60)])->saveQuietly();
                    }
                }

                DB::table('sessions')->where('user_id', $mainId)->delete();
            });
        } catch (Throwable) {
            // Best effort — see class docblock. Never break the pause action.
        }
    }
}
