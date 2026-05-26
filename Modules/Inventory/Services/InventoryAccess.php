<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use App\Models\User;

/**
 * Centralised inventory access rules. Two coarse-grained roles:
 *
 *   canAccess()  — see and create inventory entries. Admins always have it;
 *                  non-admins need to be in the `inventory_user` group.
 *   canApprove() — validate (post) an entry, irreversibly moving stock.
 *                  Admin-only. Non-admins (incl. `inventory_user`) can
 *                  draft entries but never apply them; an admin reviews
 *                  and clicks Validate to record the move.
 *
 * Use from every inventory-facing Livewire `mount()` to abort 403 if the
 * caller doesn't belong here, and from views to hide gated UI.
 *
 * Stateless / pure — the user object carries everything we need.
 */
final class InventoryAccess
{
    public const GROUP_USER = 'inventory_user';

    public static function canAccess(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        return $user->groups()->where('code', self::GROUP_USER)->exists();
    }

    public static function canApprove(?User $user): bool
    {
        return $user?->isAdmin() ?? false;
    }
}
