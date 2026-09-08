<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Erp\Admin\StaffRole;
use App\Erp\Admin\UserProvisioner;
use App\Erp\Business\Features;
use App\Erp\Enums\ModuleState;
use App\Erp\Security\SessionKiller;
use App\Erp\Tenancy\WorkspaceManager;
use App\Livewire\Concerns\ConfirmsWithEmailOtp;
use App\Models\Auth\Group;
use App\Models\Auth\ModelAccess;
use App\Models\Ir\IrModel;
use App\Models\Ir\IrModule;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\WelcomeCredentials;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Throwable;
use Livewire\Attributes\Locked;

/**
 * Admin-only "Users" tab in Settings: create or edit a user account (name /
 * email — the password is generated and emailed) and grant **view-only** access to a chosen set of apps and
 * databases. Embedded inside the Settings page under its own tab.
 *
 * Every action re-checks the admin gate (defence in depth against a crafted
 * Livewire payload) — the tab is only rendered for admins, but the component
 * never trusts that alone. The list + edit + delete operate on the CURRENT
 * (Main) database; the create form's database picker also seeds the account
 * into other databases.
 */
final class UserManager extends Component
{
    use ConfirmsWithEmailOtp;

    /** Set while editing an existing user; null in create mode. */
    #[Locked]
    public ?int $editingId = null;

    public string $name = '';

    public string $email = '';

    /**
     * Editing a GLOBAL account (one shared with every database) from inside
     * a workspace. Its name, email and role belong to Main and stay put;
     * `ir_model_access` rules are per-database rows, so its app access HERE
     * is the one thing this screen may change.
     */
    public bool $editingGlobal = false;

    /**
     * The ONE role this account holds — see {@see StaffRole}. Mutually
     * exclusive: an Administrator is not also an Accountant. Held as the
     * enum's string value because Livewire array/scalar props can't carry a
     * BackedEnum safely (memory: livewire-backed-enum-in-array-prop).
     */
    public string $role = StaffRole::Staff->value;

    /** @var list<string> Selected application module names to grant. */
    public array $apps = [];

    /** @var list<int> Selected workspace ids to create the account in (create mode). */
    public array $workspaces = [];

    /** Create the user LOCKED to a single database (workspace-only super admin). */
    public bool $lockToWorkspace = false;

    /** The single workspace to lock to when {@see $lockToWorkspace} is on. */
    public ?int $lockWorkspaceId = null;

    public function mount(): void
    {
        $this->guardAdmin();
    }

    private function guardAdmin(): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->isAdmin(), 403);
    }

    /**
     * Is the request on the MAIN database (the identity store)?
     *
     * The tenancy middleware routes a workspace request onto the shared
     * 'tenant' connection; anything else means we're on Main. Using the active
     * connection (not the cookie) reflects the database a new user would
     * actually be written to.
     */
    private function onMain(): bool
    {
        return DB::getDefaultConnection() !== 'tenant';
    }

    /**
     * The workspace this screen is operating inside, or null on Main.
     *
     * Users CAN be added from inside a workspace — they're simply created as
     * accounts that belong to it (locked): the real row + app grants live in
     * this database, and a bare non-admin login shell is written to Main behind
     * the scenes, because logins are only ever authenticated against Main. The
     * admin never has to switch databases to do it.
     */
    private function currentWorkspaceId(): ?int
    {
        if ($this->onMain()) {
            return null;
        }

        // Resolve the workspace from the ACTIVE tenant connection (its SQLite
        // file) rather than the cookie: the connection is the database we'd
        // actually be writing to, and a locked admin is routed here with no
        // cookie at all. (`Workspace` is pinned to Main, so this reads the
        // registry even though the default connection is a tenant.)
        $path = config('database.connections.tenant.database');
        if (is_string($path) && $path !== '') {
            $workspace = Workspace::query()->where('database', basename($path))->first();
            if ($workspace !== null) {
                return (int) $workspace->id;
            }
        }

        // Fallbacks: a locked admin's home workspace, then the cookie.
        $locked = $this->actor()?->homeWorkspaceId();
        if ($locked !== null) {
            return $locked;
        }

        $workspace = app(WorkspaceManager::class)->current();

        return $workspace->is_main ? null : (int) $workspace->id;
    }

    /**
     * Does this user belong to the workspace we're inside? Only such accounts
     * are editable from here — a global account (copied into every database,
     * shown as "Managed on Main") stays read-only inside a tenant, since
     * changing it here would silently affect every other database too.
     */
    private function belongsHere(User $user, int $workspaceId): bool
    {
        return (int) $user->home_workspace_id === $workspaceId;
    }

    private function actor(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    private function actorIsSuperAdmin(): bool
    {
        return $this->actor()?->isSuperAdmin() === true;
    }

    /**
     * A regular admin may not edit/delete a SUPER admin (privilege-escalation
     * guard — otherwise they could seize the owner account). Only a super admin
     * can manage another super admin.
     */
    private function actorCanManage(User $target): bool
    {
        return ! $target->isSuperAdmin() || $this->actorIsSuperAdmin();
    }

    /** The role currently selected in the form. */
    private function selectedRole(): StaffRole
    {
        return StaffRole::tryFrom($this->role) ?? StaffRole::Staff;
    }

    /**
     * The roles this admin may hand out. Super admin and Accountant are
     * owner-only (the latter can confirm money was received — deliberately not
     * in a regular admin's gift, matching the old owner-only toggle).
     *
     * @return list<StaffRole>
     */
    private function assignableRoles(): array
    {
        $isSuper = $this->actorIsSuperAdmin();

        return array_values(array_filter(
            StaffRole::all(),
            static fn (StaffRole $role): bool => $isSuper || ! $role->needsSuperAdminToAssign(),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        // A global account's identity is Main's; only its app access here is
        // editable, so there is nothing else to check.
        if ($this->editingGlobal) {
            return ['apps' => ['array'], 'apps.*' => ['string']];
        }

        $userId = $this->editingId;

        $assignable = array_map(
            static fn (StaffRole $role): string => $role->value,
            $this->assignableRoles(),
        );

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('users', 'name')->ignore($userId)],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            // Only roles this admin may actually assign — a crafted payload
            // asking for `super` or `accountant` from a regular admin fails here.
            'role' => ['required', Rule::in($assignable)],
            'apps' => ['array'],
            'apps.*' => ['string'],
            // At least one database when creating on Main — unless the account
            // is locked to a single workspace, which uses its own picker below.
            // Inside a workspace there's nothing to pick: the account belongs to
            // the database you're in.
            'workspaces' => ($userId === null && ! $this->lockToWorkspace && $this->onMain()) ? ['array', 'min:1'] : ['array'],
            'workspaces.*' => ['integer'],
            'lockWorkspaceId' => $this->lockToWorkspace ? ['required', 'integer'] : ['nullable', 'integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'workspaces.min' => __('Pick at least one database.'),
            'role.in' => __('Only a super admin can assign that role.'),
        ];
    }

    /**
     * A strong password nobody has to type: 16 letters and digits (~95 bits),
     * no symbols so it survives being copied out of an email. It is emailed
     * to the person and never shown to the admin.
     */
    private function generatePassword(): string
    {
        return Str::password(16, symbols: false);
    }

    /**
     * Email the new account its credentials and return the message to show
     * the admin. The account is already written by the time this runs, so a
     * mail failure (SMTP down) must not undo it — the person can still get in
     * through "Forgot your password?", and the admin is told so.
     */
    private function welcome(string $name, string $email, #[\SensitiveParameter] string $password): string
    {
        try {
            Notification::route('mail', $email)->notify(new WelcomeCredentials($name, $email, $password));
        } catch (Throwable $e) {
            report($e);

            return __('User created, but the email could not be sent. Ask them to use “Forgot your password?” on the sign-in page.');
        }

        return __('User created. Their password has been emailed to :email.', ['email' => $email]);
    }

    /**
     * Load an existing user into the form for editing (name / email / current
     * app grants). Passwords are never edited here — the person changes their
     * own from "Forgot your password?" on the sign-in screen.
     */
    public function editUser(int $id): void
    {
        $this->guardAdmin();

        $user = User::query()->find($id);
        if ($user === null || ! $this->actorCanManage($user)) {
            return;
        }

        // Inside a workspace this database's own accounts are fully editable.
        // A GLOBAL account is not — it is shared with every database — but its
        // app access here is a per-database row, so that much is ours to set.
        $workspaceId = $this->currentWorkspaceId();
        $this->editingGlobal = false;
        if ($workspaceId !== null && ! $this->belongsHere($user, $workspaceId)) {
            // An administrator bypasses the ACL, so a global admin has no
            // grants to give and nothing here to open.
            if ($user->home_workspace_id !== null || $user->isAdmin()) {
                return;
            }

            $this->editingGlobal = true;
        }

        $this->editingId = (int) $user->getKey();
        $this->name = (string) $user->name;
        $this->email = (string) $user->email;
        // The account's real role, read back from its flags + ACL rules (Staff
        // vs Supervisor is only visible in the rules).
        $this->role = app(UserProvisioner::class)->roleOf($user)->value;
        $this->apps = $this->currentApps($user);
        $this->workspaces = [];
        $this->lockToWorkspace = false;
        $this->lockWorkspaceId = null;
        $this->resetValidation();
    }

    public function cancelEdit(): void
    {
        $this->reset(['editingId', 'editingGlobal', 'name', 'email', 'role', 'apps', 'workspaces', 'lockToWorkspace', 'lockWorkspaceId']);
        $this->resetValidation();
    }

    /**
     * Apps a user currently has view access to, derived from their per-user
     * group's Read rules (model → owning module).
     *
     * @return list<string>
     */
    private function currentApps(User $user): array
    {
        $group = Group::query()->where('code', 'user:' . $user->getKey())->first();
        if ($group === null) {
            return [];
        }

        $models = ModelAccess::query()->where('group_id', $group->id)->pluck('model');

        return IrModel::query()
            ->whereIn('model', $models)
            ->pluck('module')
            ->unique()
            ->values()
            ->all();
    }

    public function save(): void
    {
        $this->guardAdmin();
        $this->validate();

        // Inside a workspace the account belongs to THIS database (locked) —
        // no database picker, no trip back to Main.
        $workspaceId = $this->currentWorkspaceId();
        if ($workspaceId !== null) {
            $this->saveInWorkspace($workspaceId);

            return;
        }

        if ($this->editingId !== null) {
            // Editing an existing user is a sensitive action — a regular admin
            // must confirm an emailed code first (super admin is exempt).
            if (! $this->requireOtp('user.update', ['id' => $this->editingId])) {
                return;
            }

            $this->updateExisting();

            return;
        }

        $email = strtolower(trim($this->email));
        $password = $this->generatePassword();

        if ($this->lockToWorkspace) {
            // A workspace-locked owner is all-powerful inside that database, so
            // only a super admin may mint one.
            if (! $this->actorIsSuperAdmin()) {
                $this->addError('lockToWorkspace', __('Only a super admin can create a workspace-locked admin.'));

                return;
            }

            app(UserProvisioner::class)->provisionLocked(
                trim($this->name),
                $email,
                $password,
                (int) $this->lockWorkspaceId,
                role: StaffRole::SuperAdmin,
            );
        } else {
            app(UserProvisioner::class)->provision(
                trim($this->name),
                $email,
                $password,
                array_values($this->apps),
                array_map('intval', array_values($this->workspaces)),
                $this->selectedRole(),
            );
        }

        app(\App\Erp\Activity\ActivityLogger::class)->log('user_created', $email, __('Created :name', ['name' => trim($this->name)]));

        $name = trim($this->name);
        $this->reset(['name', 'email', 'role', 'apps', 'workspaces', 'lockToWorkspace', 'lockWorkspaceId']);
        session()->flash('user_saved', $this->welcome($name, $email, $password));
    }

    /**
     * Create or edit an account that belongs to the workspace we're inside.
     * The identity still needs a row on Main (that's where logins are checked),
     * so the provisioner writes a non-admin login shell there and the real
     * account — role + app grants — in this database. To the admin it's just
     * "add a user", no database switching.
     */
    private function saveInWorkspace(int $workspaceId): void
    {
        // A global account: its identity is Main's, so only its app access in
        // this database changes and none of the checks below apply.
        if ($this->editingGlobal && $this->editingId !== null) {
            if (! $this->requireOtp('user.update', ['id' => $this->editingId])) {
                return;
            }

            $this->writeGlobalAccessHere();

            return;
        }

        $email = strtolower(trim($this->email));

        // Logins are keyed by email on Main. Refuse an email already owned by a
        // global account or by another workspace's user rather than clobber it.
        if (app(UserProvisioner::class)->mainEmailConflict($email, $workspaceId)) {
            $this->addError('email', __('That email already belongs to another account. Use a different one.'));

            return;
        }

        if ($this->editingId !== null) {
            $target = User::query()->find($this->editingId);
            if ($target === null || ! $this->actorCanManage($target) || ! $this->belongsHere($target, $workspaceId)) {
                return;
            }

            // Editing a user is sensitive — a regular admin confirms an emailed
            // code first (super admin is exempt).
            if (! $this->requireOtp('user.update', ['id' => $this->editingId])) {
                return;
            }
        }

        $this->writeWorkspaceUser($workspaceId, updating: $this->editingId !== null);
    }

    /**
     * Write the workspace-scoped account (both rows) and reset the form.
     * A new account gets a generated password, emailed to them. On an edit the role is put
     * through {@see safeRole()} so this can't strip the last admin / super
     * admin of the database, or demote the person doing the editing.
     */
    private function writeWorkspaceUser(int $workspaceId, bool $updating): void
    {
        $email = strtolower(trim($this->email));
        $name = trim($this->name);

        $password = $updating ? null : $this->generatePassword();

        $role = $this->selectedRole();
        if ($updating) {
            $existing = User::query()->find($this->editingId);
            if ($existing !== null) {
                $role = $this->safeRole($existing, $role);
            }
        }

        app(UserProvisioner::class)->provisionLocked(
            $name,
            $email,
            $password,
            $workspaceId,
            role: $role,
            appNames: $role->grantsApps() ? array_values($this->apps) : [],
        );

        app(\App\Erp\Activity\ActivityLogger::class)->log(
            $updating ? 'user_updated' : 'user_created',
            $email,
            $updating ? __('Updated :name', ['name' => $name]) : __('Created :name', ['name' => $name]),
        );

        $this->reset(['editingId', 'editingGlobal', 'name', 'email', 'role', 'apps', 'workspaces', 'lockToWorkspace', 'lockWorkspaceId']);
        session()->flash('user_saved', $updating ? __('User updated.') : $this->welcome($name, $email, (string) $password));
    }

    /**
     * Delete an account that belongs to this workspace — its row + grants here,
     * and its Main login shell. Guarded so a global account (or the last admin
     * of this database, or yourself) can never be removed from inside a tenant.
     */
    private function performWorkspaceDelete(int $id, int $workspaceId): void
    {
        $target = User::query()->find($id);

        if ($target === null || ! $this->canDeleteHere($target, $workspaceId)) {
            return;
        }

        $email = (string) $target->email;
        $name = (string) $target->name;

        app(UserProvisioner::class)->deleteLocked($email, $workspaceId);

        app(\App\Erp\Activity\ActivityLogger::class)->log('user_deleted', $email, __('Deleted :name', ['name' => $name]));

        if ($this->editingId === $id) {
            $this->cancelEdit();
        }
    }

    /** Shared guards for deleting a workspace-scoped account. */
    private function canDeleteHere(User $target, int $workspaceId): bool
    {
        return $this->actorCanManage($target)
            && $this->belongsHere($target, $workspaceId)
            && $target->getKey() !== Auth::id()
            && ! ($target->isAdmin() && User::query()->where('is_admin', true)->count() <= 1);
    }

    /**
     * The role we'll actually write. The picked role is honoured EXCEPT where it
     * would lock everyone out or let an admin demote themselves: you can't strip
     * the last super admin or the last admin of their tier, and you can't demote
     * yourself. In those cases the existing tier is kept.
     */
    private function safeRole(User $user, StaffRole $wanted): StaffRole
    {
        $isSelf = $user->getKey() === Auth::id();

        if ($user->isSuperAdmin() && ! $wanted->isSuperAdmin()) {
            $lastSuper = User::query()->where('is_super_admin', true)->count() <= 1;
            if ($isSelf || $lastSuper) {
                return StaffRole::SuperAdmin;
            }
        }

        if ($user->isAdmin() && ! $wanted->isAdmin()) {
            $lastAdmin = User::query()->where('is_admin', true)->count() <= 1;
            if ($isSelf || $lastAdmin) {
                return $user->isSuperAdmin() ? StaffRole::SuperAdmin : StaffRole::Admin;
            }
        }

        return $wanted;
    }

    /**
     * Update the edited user on the current (Main) database. The role owns all
     * three flags (admin / super admin / accountant — mutually exclusive) and
     * the permission level of the app grants; admins bypass the ACL, so no rules
     * are written for them.
     */
    private function updateExisting(): void
    {
        $user = User::query()->find($this->editingId);
        if ($user === null || ! $this->actorCanManage($user)) {
            return;
        }

        $role = $this->safeRole($user, $this->selectedRole());

        $user->name = trim($this->name);
        $user->email = strtolower(trim($this->email));

        $user->is_admin = $role->isAdmin();
        $user->is_super_admin = $role->isSuperAdmin();
        $user->is_accountant = $role->isAccountant();

        $user->save();

        // Admins bypass the ACL — grants only matter (and are rebuilt) below that.
        if ($role->grantsApps()) {
            app(UserProvisioner::class)->grantApps($user, array_values($this->apps), $role);
        }

        app(\App\Erp\Activity\ActivityLogger::class)->log('user_updated', (string) $user->email, __('Updated :name', ['name' => (string) $user->name]));

        $this->reset(['editingId', 'editingGlobal', 'name', 'email', 'role', 'apps', 'workspaces']);
        session()->flash('user_saved', __('User updated.'));
    }

    public function deleteUser(int $id): void
    {
        $this->guardAdmin();

        $workspaceId = $this->currentWorkspaceId();
        if ($workspaceId !== null) {
            $target = User::query()->find($id);
            if ($target === null || ! $this->canDeleteHere($target, $workspaceId)) {
                return;
            }

            if (! $this->requireOtp('user.delete', ['id' => $id])) {
                return;
            }

            $this->performWorkspaceDelete($id, $workspaceId);

            return;
        }

        if (! $this->canDelete($id)) {
            return;
        }

        // Deleting a user is sensitive — a regular admin confirms an emailed
        // code first (super admin is exempt).
        if (! $this->requireOtp('user.delete', ['id' => $id])) {
            return;
        }

        $this->performDelete($id);
    }

    /**
     * Guards shared by the request and the post-OTP confirmation: target
     * exists, is manageable by the actor, isn't the actor themselves, and
     * isn't the last admin / last super admin.
     */
    private function canDelete(int $id): bool
    {
        $target = User::query()->find($id);
        if ($target === null || ! $this->actorCanManage($target)) {
            return false;
        }

        if ($target->getKey() === Auth::id()) {
            return false;
        }

        if ($target->isSuperAdmin() && User::query()->where('is_super_admin', true)->count() <= 1) {
            return false;
        }

        if ($target->isAdmin() && User::query()->where('is_admin', true)->count() <= 1) {
            return false;
        }

        return true;
    }

    private function performDelete(int $id): void
    {
        $target = User::query()->find($id);
        if ($target === null) {
            return;
        }

        $email = (string) $target->email;
        $name = (string) $target->name;
        app(UserProvisioner::class)->deleteUser($target);

        app(\App\Erp\Activity\ActivityLogger::class)->log('user_deleted', $email, __('Deleted :name', ['name' => $name]));

        if ($this->editingId === $id) {
            $this->cancelEdit();
        }
    }

    /**
     * Pause or unpause an account. Pausing signs it out of every session
     * immediately ({@see SessionKiller}) and, from then on, both the sign-in
     * screen and {@see \App\Http\Middleware\EnsureUserIsNotPaused} refuse it
     * until an admin unpauses it. This is sensitive enough (it can lock
     * someone out on the spot) to carry the same email-OTP gate as edit/delete.
     */
    public function togglePause(int $id): void
    {
        $this->guardAdmin();

        if (! $this->requireOtp('user.pause', ['id' => $id])) {
            return;
        }

        $this->performTogglePause($id);
    }

    private function performTogglePause(int $id): void
    {
        // Deliberately NOT scoped by belongsHere() like edit/delete: pausing
        // isn't an identity edit, it's "block this account's access to the
        // database I'm looking at right now" — including a GLOBAL account's
        // copy in this one workspace, without needing a trip to Main. $target
        // is looked up on the CURRENTLY ACTIVE connection, so it can only ever
        // resolve to a row that already exists in this database.
        $target = User::query()->find($id);
        if ($target === null || ! $this->actorCanManage($target)) {
            return;
        }

        $pausing = ! $target->isPaused();

        if ($pausing && ! $this->canPause($target)) {
            return;
        }

        $target->is_paused = $pausing;
        $target->paused_at = $pausing ? now() : null;
        $target->save();

        if ($pausing) {
            app(SessionKiller::class)->killFor($target);
        }

        app(\App\Erp\Activity\ActivityLogger::class)->log(
            $pausing ? 'user_paused' : 'user_unpaused',
            (string) $target->email,
            $pausing
                ? __('Paused :name', ['name' => $target->name])
                : __('Unpaused :name', ['name' => $target->name]),
        );
    }

    /**
     * Guard for turning pause ON: never yourself (instant, unrecoverable
     * self-lockout) and never the last admin (nobody left to unpause them).
     * Turning pause OFF carries no such risk and needs no guard.
     */
    private function canPause(User $target): bool
    {
        if ($target->getKey() === Auth::id()) {
            return false;
        }

        return ! ($target->isAdmin() && User::query()->where('is_admin', true)->count() <= 1);
    }

    /**
     * The role badge for every row of the list, resolved in ONE extra query.
     * Super admin / Admin / Accountant come straight off the flags; Supervisor
     * is only visible in the ACL (their grants carry Write), so the per-user
     * groups that hold a Write rule are fetched in bulk rather than per row.
     *
     * @param  \Illuminate\Support\Collection<int, User>  $users
     * @return array<int, StaffRole>
     */
    private function rolesFor($users): array
    {
        $codes = $users->map(static fn (User $u): string => 'user:' . $u->getKey())->all();

        $supervisorCodes = Group::query()
            ->whereIn('code', $codes)
            ->whereIn('id', ModelAccess::query()->where('perm_write', true)->select('group_id'))
            ->pluck('code')
            ->flip();

        $roles = [];

        foreach ($users as $user) {
            $role = StaffRole::of($user);

            if ($role === StaffRole::Staff && $supervisorCodes->has('user:' . $user->getKey())) {
                $role = StaffRole::Supervisor;
            }

            $roles[(int) $user->getKey()] = $role;
        }

        return $roles;
    }

    // NOTE: the old inline `toggleSuperAdmin` / `toggleAccountant` row buttons
    // are GONE. Both tiers are now roles in the edit form's role picker (one
    // mutually-exclusive choice per account), so there's a single place a role
    // is set — and one set of guards (`safeRole()` + the `role.in` rule, which
    // only lets a super admin assign the Super admin / Accountant roles).

    /**
     * Execute an action the email-OTP just confirmed (regular-admin path).
     * Re-guards/re-validates as defence in depth.
     *
     * @param array<string, mixed> $args
     */
    protected function runConfirmedAction(string $action, array $args): void
    {
        $this->guardAdmin();

        match ($action) {
            'user.update' => $this->confirmedUpdate(),
            'user.delete' => $this->confirmedDelete((int) ($args['id'] ?? 0)),
            'user.pause' => $this->performTogglePause((int) ($args['id'] ?? 0)),
            default => null,
        };
    }

    /**
     * Give a GLOBAL account its app access in THIS database. Name, email and
     * role belong to Main and are left alone; `ir_model_access` rules are
     * per-database rows, so nothing here can reach another database.
     *
     * This is the only way to grant an app the current database runs but Main
     * does not — Rent A Car inside a rental workspace, say. Main's own
     * checklist never offers it, because Main is not that kind of business,
     * so the tick could not be made there at all.
     */
    private function writeGlobalAccessHere(): void
    {
        $target = User::query()->find($this->editingId);

        if ($target === null || ! $this->actorCanManage($target)
            || $target->home_workspace_id !== null || $target->isAdmin()) {
            return;
        }

        $provisioner = app(UserProvisioner::class);
        $role = $provisioner->roleOf($target);

        // An administrator bypasses the ACL, so there are no grants to write.
        if ($role->grantsApps()) {
            $provisioner->grantApps($target, array_values($this->apps), $role);
        }

        app(\App\Erp\Activity\ActivityLogger::class)->log(
            'user_updated',
            (string) $target->email,
            __('Set :name’s app access for this database', ['name' => (string) $target->name]),
        );

        $this->reset(['editingId', 'editingGlobal', 'name', 'email', 'role', 'apps', 'workspaces', 'lockToWorkspace', 'lockWorkspaceId']);
        session()->flash('user_saved', __('App access updated for this database.'));
    }

    private function confirmedUpdate(): void
    {
        $this->validate();

        if ($this->editingGlobal) {
            $this->writeGlobalAccessHere();

            return;
        }

        $workspaceId = $this->currentWorkspaceId();
        if ($workspaceId !== null) {
            $this->writeWorkspaceUser($workspaceId, updating: true);

            return;
        }

        $this->updateExisting();
    }

    private function confirmedDelete(int $id): void
    {
        $workspaceId = $this->currentWorkspaceId();
        if ($workspaceId !== null) {
            $this->performWorkspaceDelete($id, $workspaceId);

            return;
        }

        if ($this->canDelete($id)) {
            $this->performDelete($id);
        }
    }

    public function render(): View
    {
        // Only the apps this database's business type actually exposes — the
        // same gate the top app bar and module menus use. Without it the tab
        // offered Rent A Car / Limousine / … on a database that doesn't run
        // them, and ticking one granted access to an app the user can't see.
        /** @var \Illuminate\Support\Collection<int, IrModule> $apps */
        $apps = IrModule::query()
            ->where('application', true)
            ->where('state', ModuleState::Installed)
            ->orderBy('sequence')
            ->get()
            ->filter(static fn (IrModule $module): bool => Features::moduleAllowed((string) $module->name))
            ->values();

        // All databases (Main + tenants) — Main is no longer implicit, the
        // admin picks it like any other.
        $workspaces = Schema::hasTable('workspaces')
            ? app(WorkspaceManager::class)->all()
            : collect();

        $workspaceId = $this->currentWorkspaceId();
        $workspaceName = null;
        if ($workspaceId !== null) {
            $workspace = app(WorkspaceManager::class)->find($workspaceId);
            $workspaceName = $workspace === null ? (string) __('this database') : $workspace->name;
        }

        $users = User::query()
            ->orderByDesc('is_super_admin')
            ->orderByDesc('is_admin')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'is_admin', 'is_super_admin', 'is_accountant', 'is_paused', 'home_workspace_id']);

        return view('livewire.settings.user-manager', [
            // NB: keys must NOT clash with the public $apps / $workspaces
            // properties (Livewire injects those into the view too).
            'appModules' => $apps,
            'workspaceList' => $workspaces,
            // Id → name for the "locked to …" tag in the list.
            'workspaceNames' => $workspaces->pluck('name', 'id'),
            'users' => $users,
            // Id → the ONE role each holds, for the list badge.
            'userRoles' => $this->rolesFor($users),
            'currentUserId' => Auth::id(),
            'adminCount' => User::query()->where('is_admin', true)->count(),
            'actorIsSuperAdmin' => $this->actorIsSuperAdmin(),
            'onMain' => $this->onMain(),
            // The role picker: one mutually-exclusive choice. Super admin and
            // Accountant only appear for a super admin (owner-only to assign).
            'roleOptions' => $this->assignableRoles(),
            'currentRole' => $this->selectedRole(),
            // Inside a workspace: its id + name, so the form can say which
            // database the new account will belong to and the list can gate
            // per-row actions to this database's own users.
            'workspaceId' => $workspaceId,
            'workspaceName' => $workspaceName,
        ]);
    }
}
