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
     */
    public function provision(
        string $name,
        string $email,
        string $plainPassword,
        array $appNames,
        array $workspaceIds,
    ): ?User {
        $hashed = Hash::make($plainPassword);

        // No workspace feature on disk → just create on the current connection.
        if (! Schema::hasTable('workspaces')) {
            return $this->upsertWithAccess($name, $email, $hashed, $appNames);
        }

        $mainUser = null;

        foreach ($workspaceIds as $workspaceId) {
            $workspace = Workspace::query()->find($workspaceId);
            if ($workspace === null) {
                continue;
            }

            // Main = the current (default) connection.
            if ($workspace->is_main) {
                $mainUser = $this->upsertWithAccess($name, $email, $hashed, $appNames);

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
                fn (): User => $this->upsertWithAccess($name, $email, $hashed, $appNames),
            );
        }

        return $mainUser;
    }

    /**
     * Create/update the user, then (re)grant the apps — all on the CURRENT
     * default connection.
     *
     * @param  list<string>  $appNames
     */
    private function upsertWithAccess(string $name, string $email, string $hashedPassword, array $appNames): User
    {
        return DB::transaction(function () use ($name, $email, $hashedPassword, $appNames): User {
            $user = User::query()->updateOrCreate(
                ['email' => $email],
                ['name' => $name, 'is_admin' => false, 'password' => $hashedPassword],
            );

            $this->grantApps($user, $appNames);

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
