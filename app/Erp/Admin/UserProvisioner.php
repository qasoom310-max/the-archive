<?php

declare(strict_types=1);

namespace App\Erp\Admin;

use App\Erp\Tenancy\WorkspaceManager;
use App\Models\Auth\Group;
use App\Models\Auth\ModelAccess;
use App\Models\Ir\IrModel;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * Creates a non-admin staff user with **view-only** access to a chosen set of
 * apps, in the Main database AND in each selected workspace ("database").
 *
 * App access is modelled exactly like the rest of the ACL system: each user
 * gets a dedicated per-user group (`code = user:{id}`) and one `ir_model_access`
 * Read rule per registered model of every granted app. Re-running for the same
 * email edits the user in place (the per-user group's rules are rebuilt to
 * match the current app selection).
 *
 * Database access is **provision-only**: the same account (matched by email,
 * the way the tenancy already matches identities across databases) is created
 * inside each selected workspace's SQLite file with the same view-only grants.
 * Switching between databases is unchanged — still admin-only.
 */
final class UserProvisioner
{
    public function __construct(private readonly WorkspaceManager $workspaces) {}

    /**
     * Create the user (matched by email) in EACH selected database — Main only
     * if its workspace is among the selections (no longer implicit). Returns
     * the Main user when Main was selected, else null.
     *
     * @param  list<string>  $appNames      installed application module names to grant Read on
     * @param  list<int>     $workspaceIds  workspace ids (Main + tenants) to create the account in
     * @param  bool          $isAdmin       create as a full admin (bypasses ACL — app grants ignored)
     */
    public function provision(
        string $name,
        string $email,
        string $plainPassword,
        array $appNames,
        array $workspaceIds,
        bool $isAdmin = false,
    ): ?User {
        $hashed = Hash::make($plainPassword);

        // No workspace feature on disk → just create on the current connection.
        if (! Schema::hasTable('workspaces')) {
            return $this->upsertWithAccess($name, $email, $hashed, $appNames, $isAdmin);
        }

        $mainUser = null;

        foreach ($workspaceIds as $workspaceId) {
            $workspace = Workspace::query()->find($workspaceId);
            if ($workspace === null) {
                continue;
            }

            // Main = the current (default) connection.
            if ($workspace->is_main) {
                $mainUser = $this->upsertWithAccess($name, $email, $hashed, $appNames, $isAdmin);

                continue;
            }

            // Tenant = its own SQLite file. Matched by email, the same way
            // SetActiveWorkspace rebinds identity across databases.
            $path = $workspace->databasePath();
            if ($path === null || ! is_file($path)) {
                continue;
            }

            $this->workspaces->withTenant(
                $path,
                fn (): User => $this->upsertWithAccess($name, $email, $hashed, $appNames, $isAdmin),
            );
        }

        return $mainUser;
    }

    /**
     * Create a user LOCKED to a single workspace: full admin (optionally the
     * owner tier) INSIDE that database, but with a bare non-admin shell on Main
     * used only to authenticate. The tenancy layer forces them into the
     * workspace on every request, so they can never touch Main or any other
     * database. Returns the Main shell (or, if the tenancy tables are absent, a
     * plain admin on the current DB).
     *
     * Re-running for the same email edits both rows in place.
     */
    public function provisionLocked(
        string $name,
        string $email,
        string $plainPassword,
        int $workspaceId,
        bool $superAdmin = true,
    ): User {
        $hashed = Hash::make($plainPassword);

        // No tenancy on disk → nothing to lock to; create a plain admin.
        if (! Schema::hasTable('workspaces')) {
            return $this->upsertLockedRow($name, $email, $hashed, null, true, $superAdmin);
        }

        $workspace = Workspace::query()->find($workspaceId);
        // Locking to Main (the identity store) is meaningless — treat as a
        // normal admin on Main rather than trapping them nowhere.
        if ($workspace === null || $workspace->is_main) {
            return $this->upsertLockedRow($name, $email, $hashed, null, true, $superAdmin);
        }

        // Main: a login shell only — NON-admin, but flagged locked so the
        // tenancy middleware routes it straight into the workspace.
        $mainUser = $this->upsertLockedRow($name, $email, $hashed, $workspaceId, false, false);

        // Tenant: the real account — full owner within this one database.
        $path = $workspace->databasePath();
        if ($path !== null && is_file($path)) {
            $this->workspaces->withTenant(
                $path,
                fn (): User => $this->upsertLockedRow($name, $email, $hashed, $workspaceId, true, $superAdmin),
            );
        }

        return $mainUser;
    }

    /**
     * Upsert a user row (by email) on the CURRENT connection with an explicit
     * role + lock. `is_admin` is forced on for a super admin (a superset).
     */
    private function upsertLockedRow(string $name, string $email, string $hashedPassword, ?int $homeWorkspaceId, bool $isAdmin, bool $isSuperAdmin): User
    {
        return User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => $hashedPassword,
                'is_admin' => $isAdmin || $isSuperAdmin,
                'is_super_admin' => $isSuperAdmin,
                'home_workspace_id' => $homeWorkspaceId,
            ],
        );
    }

    /**
     * Create/update the user, then (re)grant the apps — all on the CURRENT
     * default connection. An admin bypasses the ACL entirely, so no per-user
     * group / Read rules are created for one.
     *
     * @param  list<string>  $appNames
     */
    private function upsertWithAccess(string $name, string $email, string $hashedPassword, array $appNames, bool $isAdmin = false): User
    {
        return DB::transaction(function () use ($name, $email, $hashedPassword, $appNames, $isAdmin): User {
            $user = User::query()->updateOrCreate(
                ['email' => $email],
                ['name' => $name, 'is_admin' => $isAdmin, 'password' => $hashedPassword],
            );

            if (! $isAdmin) {
                $this->grantApps($user, $appNames);
            }

            return $user;
        });
    }

    /**
     * Rebuild a user's per-user group + view-only Read rules to exactly match
     * the given app selection (on the current connection). Used by both the
     * create path and the edit path. Idempotent.
     *
     * @param  list<string>  $appNames
     */
    public function grantApps(User $user, array $appNames): void
    {
        DB::transaction(function () use ($user, $appNames): void {
            $group = Group::query()->updateOrCreate(
                ['code' => 'user:' . $user->getKey()],
                ['name' => $user->name . ' — access', 'description' => 'Per-user app access (view only)'],
            );

            $user->groups()->syncWithoutDetaching([$group->id]);

            // Rebuild from scratch so editing the app selection can only ever
            // match the current choice.
            ModelAccess::query()->where('group_id', $group->id)->delete();

            if ($appNames === []) {
                return;
            }

            $models = IrModel::query()
                ->whereIn('module', $appNames)
                ->pluck('model')
                ->unique();

            foreach ($models as $model) {
                ModelAccess::query()->create([
                    'name' => $user->name . ': view ' . $model,
                    'model' => $model,
                    'group_id' => $group->id,
                    'perm_read' => true,
                    'perm_write' => false,
                    'perm_create' => false,
                    'perm_unlink' => false,
                ]);
            }
        });
    }

    /**
     * Remove a staff user and their per-user access group (Main only — tenant
     * copies are left in place, harmless without a login). Admins and the
     * acting user are protected by the caller.
     */
    public function deleteUser(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $group = Group::query()->where('code', 'user:' . $user->getKey())->first();

            if ($group !== null) {
                ModelAccess::query()->where('group_id', $group->id)->delete();
                $user->groups()->detach();
                $group->delete();
            }

            $user->delete();
        });
    }
}
