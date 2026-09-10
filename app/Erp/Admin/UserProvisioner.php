<?php

declare(strict_types=1);

namespace App\Erp\Admin;

use App\Erp\Business\Features;
use App\Erp\Tenancy\WorkspaceManager;
use App\Models\Auth\Group;
use App\Models\Auth\ModelAccess;
use App\Models\Ir\IrModel;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

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
     * Create the user (matched by email) in EACH selected database — with
     * Main app grants only if Main's workspace is among the selections (no
     * longer implicit). Returns the Main user when Main was selected.
     *
     * Login always checks Main FIRST, regardless of which databases the
     * account was actually granted (there is no cookie yet to route a
     * brand-new browser session anywhere else — see `ChooseWorkspaceController`).
     * So a tenant-only account still needs A row on Main to authenticate with,
     * even though it should hold none of Main's own app access. When Main
     * wasn't ticked, {@see ensureMainLoginShell()} writes that bare row: no
     * admin flags, no ACL grants — `WorkspaceManager::accessibleFor()` already
     * excludes Main from a non-admin's workspace picker, so this can't leak
     * Main access. Before this, such an account could never sign in at all —
     * "credentials do not match" for every new hire whose owner (reasonably)
     * ticked only their own business's database.
     *
     * @param  list<string>  $appNames      installed application module names to grant on
     * @param  list<int>     $workspaceIds  workspace ids (Main + tenants) to create the account in
     * @param  StaffRole     $role          the one role the account holds (drives flags + granted permissions)
     */
    public function provision(
        string $name,
        string $email,
        string $plainPassword,
        array $appNames,
        array $workspaceIds,
        StaffRole $role = StaffRole::Staff,
    ): ?User {
        $hashed = Hash::make($plainPassword);

        // No workspace feature on disk → just create on the current connection.
        if (! Schema::hasTable('workspaces')) {
            return $this->upsertWithAccess($name, $email, $hashed, $appNames, $role);
        }

        $mainUser = null;

        foreach ($workspaceIds as $workspaceId) {
            $workspace = Workspace::query()->find($workspaceId);
            if ($workspace === null) {
                continue;
            }

            // Main = the landlord connection. Written explicitly (not "whatever
            // is currently default") so this also works when called from inside
            // a workspace.
            if ($workspace->is_main) {
                $mainUser = $this->workspaces->withMain(
                    fn (): User => $this->upsertWithAccess($name, $email, $hashed, $appNames, $role),
                );

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
                fn (): User => $this->upsertWithAccess($name, $email, $hashed, $appNames, $role),
            );
        }

        if ($mainUser === null) {
            $mainUser = $this->ensureMainLoginShell($name, $email, $hashed);
        }

        return $mainUser;
    }

    /**
     * A bare, unprivileged row on Main so an account granted only tenant
     * workspaces can still authenticate (see {@see provision()}). No admin
     * flags, no app grants — just enough for `Auth::attempt()` to succeed;
     * `WorkspaceManager::accessibleFor()` keeps Main itself off a non-admin's
     * workspace picker regardless of this row existing.
     */
    private function ensureMainLoginShell(string $name, string $email, string $hashedPassword): User
    {
        return $this->workspaces->withMain(
            fn (): User => User::query()->updateOrCreate(
                ['email' => $email],
                ['name' => $name, 'password' => $hashedPassword],
            ),
        );
    }

    /**
     * Create a user LOCKED to a single workspace: their real account (with the
     * given role + app grants) lives INSIDE that database, while Main holds a
     * bare non-admin shell used only to authenticate. The tenancy layer forces
     * them into the workspace on every request, so they can never touch Main or
     * any other database. Returns the Main shell (or, if the tenancy tables are
     * absent, a plain admin on the current DB).
     *
     * Connection-explicit on purpose: the Main shell is written through
     * {@see WorkspaceManager::withMain()} and the real row through
     * `withTenant()`, so this works identically whether it's called from Main
     * OR from inside a workspace (the "add a user without switching to Main"
     * path).
     *
     * Re-running for the same email edits both rows in place. A null/blank
     * password keeps the existing one (the edit path).
     *
     * @param  list<string>  $appNames  apps to grant on (non-admin roles) or narrow to (a scopable Administrator) — meaningless for a super admin
     */
    public function provisionLocked(
        string $name,
        string $email,
        ?string $plainPassword,
        int $workspaceId,
        StaffRole $role = StaffRole::SuperAdmin,
        array $appNames = [],
    ): User {
        $hashed = ($plainPassword === null || $plainPassword === '') ? null : Hash::make($plainPassword);

        // No tenancy on disk → nothing to lock to; create the account plainly.
        if (! Schema::hasTable('workspaces')) {
            return $this->upsertLockedRow($name, $email, $hashed, null, $role, $appNames);
        }

        $workspace = Workspace::query()->find($workspaceId);
        // Locking to Main (the identity store) is meaningless — treat as a
        // normal account on Main rather than trapping them nowhere.
        if ($workspace === null || $workspace->is_main) {
            $user = $this->upsertLockedRow($name, $email, $hashed, null, $role, $appNames);
            if ($role->grantsApps()) {
                $this->grantApps($user, $appNames, $role);
            }

            return $user;
        }

        // Main: a login shell only — NEVER an admin there, and flagged locked
        // so the tenancy middleware routes it straight into the workspace.
        $mainUser = $this->workspaces->withMain(
            fn (): User => $this->upsertLockedRow($name, $email, $hashed, $workspaceId, StaffRole::Staff),
        );

        // Tenant: the real account — the chosen role within this one database.
        $path = $workspace->databasePath();
        if ($path !== null && is_file($path)) {
            $this->workspaces->withTenant($path, function () use ($name, $email, $hashed, $workspaceId, $role, $appNames): void {
                $user = $this->upsertLockedRow($name, $email, $hashed, $workspaceId, $role, $appNames);

                // Admins bypass the ACL entirely — grants only matter below that.
                if ($role->grantsApps()) {
                    $this->grantApps($user, $appNames, $role);
                }
            });
        }

        return $mainUser;
    }

    /**
     * Would writing this email as a workspace account clobber an existing MAIN
     * identity that isn't ours? Logins are keyed by email on Main, so an email
     * already owned by a global admin (or by a user locked to a DIFFERENT
     * workspace) must be refused rather than silently overwritten.
     */
    public function mainEmailConflict(string $email, int $workspaceId): bool
    {
        if (! Schema::hasTable('workspaces')) {
            return false;
        }

        return (bool) $this->workspaces->withMain(static function () use ($email, $workspaceId): bool {
            $existing = User::query()->where('email', $email)->first();

            if ($existing === null) {
                return false;
            }

            // Only our own login shell for THIS workspace may be reused.
            return (int) $existing->home_workspace_id !== $workspaceId;
        });
    }

    /**
     * Remove a user that belongs to a single workspace: their account + per-user
     * group inside that database, plus the Main login shell — but only when that
     * shell really is ours (locked to this workspace and not an admin), so a
     * global account can never be deleted from inside a tenant.
     */
    public function deleteLocked(string $email, int $workspaceId): void
    {
        if (! Schema::hasTable('workspaces')) {
            return;
        }

        $workspace = Workspace::query()->find($workspaceId);
        if ($workspace === null || $workspace->is_main) {
            return;
        }

        $path = $workspace->databasePath();
        if ($path !== null && is_file($path)) {
            $this->workspaces->withTenant($path, function () use ($email): void {
                $user = User::query()->where('email', $email)->first();
                if ($user !== null) {
                    $this->deleteUser($user);
                }
            });
        }

        $this->workspaces->withMain(function () use ($email, $workspaceId): void {
            $shell = User::query()->where('email', $email)->first();

            if ($shell !== null && (int) $shell->home_workspace_id === $workspaceId && ! $shell->isAdmin()) {
                $this->deleteUser($shell);
            }
        });
    }

    /**
     * Upsert a user row (by email) on the CURRENT connection with an explicit
     * role + lock. The role owns all three flags — `is_admin` is forced on for a
     * super admin (a superset), and `is_accountant` only for the Accountant role
     * (they're mutually exclusive). `admin_apps` narrows a scopable Administrator
     * (see {@see adminScopeFor()}); every other role stores null there. A null
     * password keeps the existing one; a brand-new row with no password gets an
     * unusable random hash rather than a null column.
     *
     * @param  list<string>  $appNames
     */
    private function upsertLockedRow(string $name, string $email, ?string $hashedPassword, ?int $homeWorkspaceId, StaffRole $role, array $appNames = []): User
    {
        if ($hashedPassword === null && ! User::query()->where('email', $email)->exists()) {
            $hashedPassword = Hash::make(Str::random(40)); // unusable placeholder
        }

        $values = [
            'name' => $name,
            'is_admin' => $role->isAdmin(),
            'is_super_admin' => $role->isSuperAdmin(),
            'admin_apps' => $this->adminScopeFor($role, $appNames),
            'is_accountant' => $role->isAccountant(),
            'home_workspace_id' => $homeWorkspaceId,
        ];

        if ($hashedPassword !== null) {
            $values['password'] = $hashedPassword;
        }

        return User::query()->updateOrCreate(['email' => $email], $values);
    }

    /**
     * Create/update the user, then (re)grant the apps — all on the CURRENT
     * default connection. An admin bypasses the ACL entirely, so no per-user
     * group / access rules are created for one; a scopable Administrator gets
     * `admin_apps` instead (see {@see adminScopeFor()}).
     *
     * @param  list<string>  $appNames
     */
    private function upsertWithAccess(string $name, string $email, string $hashedPassword, array $appNames, StaffRole $role = StaffRole::Staff): User
    {
        return DB::transaction(function () use ($name, $email, $hashedPassword, $appNames, $role): User {
            $user = User::query()->updateOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'is_admin' => $role->isAdmin(),
                    'is_super_admin' => $role->isSuperAdmin(),
                    'admin_apps' => $this->adminScopeFor($role, $appNames),
                    'is_accountant' => $role->isAccountant(),
                    'password' => $hashedPassword,
                ],
            );

            if ($role->grantsApps()) {
                $this->grantApps($user, $appNames, $role);
            }

            return $user;
        });
    }

    /**
     * The `admin_apps` value to store for this role/selection: null for every
     * role except a scopable Administrator, and null there too when nothing
     * (allowed) was ticked — an admin with no scope is unrestricted, not
     * locked out of everything, matching the pre-existing "Administrator ==
     * every app" default. Filtered through {@see Features::moduleAllowed()}
     * so a tick from a business type that doesn't run that app can't leak in.
     *
     * @param  list<string>  $appNames
     * @return list<string>|null
     */
    public function adminScopeFor(StaffRole $role, array $appNames): ?array
    {
        if (! $role->isScopableAdmin()) {
            return null;
        }

        $allowed = array_values(array_filter(
            $appNames,
            static fn (string $module): bool => Features::moduleAllowed($module),
        ));

        return $allowed === [] ? null : $allowed;
    }

    /**
     * Rebuild a user's per-user group + access rules to exactly match the given
     * app selection AND role (on the current connection). Used by both the
     * create path and the edit path. Idempotent.
     *
     * The permission set comes from the role — Staff / Accountant get Read,
     * a Supervisor also gets Write + Create. Delete is never granted here.
     *
     * Scoped to what the CURRENT database actually exposes: apps and models its
     * business type (or a manual feature toggle) hides here are dropped. Because
     * this runs once per target database — inside that database's connection,
     * and `Features` reads its own `company.business_type` — one account created
     * across several databases gets grants matching EACH of them (a café's copy
     * gets POS, a rental company's copy doesn't, from the same tick-box).
     *
     * @param  list<string>  $appNames
     */
    public function grantApps(User $user, array $appNames, StaffRole $role = StaffRole::Staff): void
    {
        DB::transaction(function () use ($user, $appNames, $role): void {
            $group = Group::query()->updateOrCreate(
                ['code' => 'user:' . $user->getKey()],
                ['name' => $user->name . ' — access', 'description' => 'Per-user app access (' . $role->value . ')'],
            );

            $user->groups()->syncWithoutDetaching([$group->id]);

            // Rebuild from scratch so editing the app selection can only ever
            // match the current choice.
            ModelAccess::query()->where('group_id', $group->id)->delete();

            $allowed = array_values(array_filter(
                $appNames,
                static fn (string $module): bool => Features::moduleAllowed($module),
            ));

            if ($allowed === []) {
                return;
            }

            $models = IrModel::query()
                ->whereIn('module', $allowed)
                ->pluck('model')
                ->unique()
                ->filter(static fn (mixed $model): bool => is_string($model) && Features::modelAllowed($model));

            $perms = $role->permissions();

            foreach ($models as $model) {
                ModelAccess::query()->create([
                    'name' => $user->name . ': ' . $role->value . ' ' . $model,
                    'model' => $model,
                    'group_id' => $group->id,
                    'perm_read' => $perms['read'],
                    'perm_write' => $perms['write'],
                    'perm_create' => $perms['create'],
                    'perm_unlink' => $perms['unlink'],
                ]);
            }
        });
    }

    /**
     * The role an existing account holds. The flags settle Super admin / Admin /
     * Accountant, but Staff vs Supervisor is NOT a column — it's the shape of
     * the ACL rules their per-user group carries (a Supervisor's grant Write).
     * Read back here so the edit form's role radio tells the truth.
     */
    public function roleOf(User $user): StaffRole
    {
        $role = StaffRole::of($user);

        if ($role !== StaffRole::Staff) {
            return $role;
        }

        $group = Group::query()->where('code', 'user:' . $user->getKey())->first();

        if ($group !== null && ModelAccess::query()->where('group_id', $group->id)->where('perm_write', true)->exists()) {
            return StaffRole::Supervisor;
        }

        return StaffRole::Staff;
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

    /**
     * Remove every copy of this email's account — Main and every workspace (or
     * a chosen subset). The CLI/command-line twin of {@see deleteUser()} for a
     * GLOBAL account: the Users tab can only ever delete one on Main (deleting
     * it from inside a workspace is refused there on purpose — see
     * {@see \App\Livewire\Settings\UserManager::canDeleteHere()}), so a true
     * "remove this login everywhere" needs this instead.
     *
     * @param  list<int>|null  $onlyWorkspaceIds  null = every database (Main included)
     * @return list<string> names of the databases a matching account was actually removed from
     */
    public function deleteEverywhere(string $email, ?array $onlyWorkspaceIds = null): array
    {
        if (! Schema::hasTable('workspaces')) {
            $removed = (bool) $this->deleteByEmailOnCurrentConnection($email);

            return $removed ? ['this database'] : [];
        }

        $workspaces = $this->workspaces->all();
        if ($onlyWorkspaceIds !== null) {
            $workspaces = $workspaces->whereIn('id', $onlyWorkspaceIds);
        }

        $removedFrom = [];

        foreach ($workspaces as $workspace) {
            if ($workspace->is_main) {
                $removed = (bool) $this->workspaces->withMain(
                    fn (): bool => $this->deleteByEmailOnCurrentConnection($email),
                );
                if ($removed) {
                    $removedFrom[] = 'Main';
                }

                continue;
            }

            $path = $workspace->databasePath();
            if ($path === null || ! is_file($path)) {
                continue;
            }

            $removed = (bool) $this->workspaces->withTenant(
                $path,
                fn (): bool => $this->deleteByEmailOnCurrentConnection($email),
            );
            if ($removed) {
                $removedFrom[] = $workspace->name;
            }
        }

        return $removedFrom;
    }

    /** Delete the user matching this email on whatever connection is CURRENTLY active, if one exists. */
    private function deleteByEmailOnCurrentConnection(string $email): bool
    {
        $user = User::query()->where('email', $email)->first();
        if ($user === null) {
            return false;
        }

        $this->deleteUser($user);

        return true;
    }
}
