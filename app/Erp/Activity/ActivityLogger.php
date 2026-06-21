<?php

declare(strict_types=1);

namespace App\Erp\Activity;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Records audit-trail entries into `activity_logs`. The actor's name + role
 * are snapshotted so the log stays readable after a rename/delete.
 *
 * Logging must NEVER break the action it's recording — every write is wrapped
 * in a guard (table presence) + try/catch, so a missing table (fresh tenant,
 * mid-deploy) or any failure is silently swallowed.
 */
final class ActivityLogger
{
    /**
     * @param  string       $action       one of {@see ActivityLog::LABELS} keys (or any code)
     * @param  string|null  $subject      short "what" (e.g. "Partner #5")
     * @param  string|null  $description  optional human-readable detail
     * @param  Authenticatable|null  $actor  override the actor (defaults to the logged-in user)
     */
    public function log(string $action, ?string $subject = null, ?string $description = null, ?Authenticatable $actor = null): void
    {
        try {
            if (! Schema::hasTable('activity_logs')) {
                return;
            }

            $actor ??= Auth::user();
            $name = $actor instanceof User ? (string) $actor->name : 'System';

            ActivityLog::query()->create([
                'user_id' => $actor instanceof User ? $actor->getKey() : null,
                'user_name' => $name,
                'user_is_admin' => $actor instanceof User && $actor->isAdmin(),
                'action' => $action,
                'subject' => $subject,
                'description' => $description,
                'ip_address' => $this->clientIp(),
                'created_at' => Carbon::now(),
            ]);
        } catch (Throwable) {
            // Never let auditing break the audited action.
        }
    }

    private function clientIp(): ?string
    {
        try {
            return request()->ip();
        } catch (Throwable) {
            return null; // no request context (console / queue)
        }
    }
}
