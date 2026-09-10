<?php

declare(strict_types=1);

namespace App\Erp\Security;

use App\Models\Auth\ModelAccess;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Resolves ir_model_access rules. Mirrors Odoo's ACL semantics:
 *  - an `is_admin` user bypasses every check (superuser) — UNLESS they are an
 *    Administrator narrowed to specific apps ({@see User::adminAppScope()}),
 *    in which case the bypass only covers models belonging to those apps;
 *    a super admin is never narrowed, regardless of that column;
 *  - otherwise access is granted only if some rule for the model — owned
 *    by one of the user's groups, or global (null group) — grants the
 *    requested permission. No rule = no access (deny by default).
 */
final class AccessControl
{
    public function allows(?Authenticatable $user, string $modelKey, Permission $permission): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        if ($user->isAdmin()) {
            return $user->mayAdministerApp($this->moduleOf($modelKey));
        }

        $groupIds = $user->groups()->pluck('res_groups.id')->all();

        return ModelAccess::query()
            ->where('model', $modelKey)
            ->where($permission->value, true)
            ->where(function (Builder $query) use ($groupIds): void {
                $query->whereNull('group_id');

                if ($groupIds !== []) {
                    $query->orWhereIn('group_id', $groupIds);
                }
            })
            ->exists();
    }

    public function denies(?Authenticatable $user, string $modelKey, Permission $permission): bool
    {
        return ! $this->allows($user, $modelKey, $permission);
    }

    /**
     * Throw (→ HTTP 403 via the exception handler) unless granted.
     *
     * @throws AuthorizationException
     */
    public function authorize(?Authenticatable $user, string $modelKey, Permission $permission): void
    {
        if ($this->denies($user, $modelKey, $permission)) {
            throw new AuthorizationException("Forbidden: {$permission->name} on {$modelKey}.");
        }
    }

    /**
     * The owning app of a model key — every key in the registry is
     * `<module>.<name>` (e.g. "pos.order", "rental.invoice"), the same
     * convention {@see \App\Erp\Admin\UserProvisioner::grantApps()} relies on.
     */
    private function moduleOf(string $modelKey): string
    {
        $module = strstr($modelKey, '.', true);

        return $module === false ? $modelKey : $module;
    }
}
