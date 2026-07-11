<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Erp\Admin\UserProvisioner;
use App\Erp\Enums\ModuleState;
use App\Erp\Tenancy\WorkspaceManager;
use App\Livewire\Concerns\ConfirmsWithEmailOtp;
use App\Models\Auth\Group;
use App\Models\Auth\ModelAccess;
use App\Models\Ir\IrModel;
use App\Models\Ir\IrModule;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Admin-only "Users" tab in Settings: create or edit a user account (name /
 * email / password) and grant **view-only** access to a chosen set of apps and
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
    public ?int $editingId = null;

    public string $name = '';

    public string $email = '';

    public string $password = '';

    /** Account role: 'staff' (view-only ACL) or 'admin' (full access). */
    public string $role = 'staff';

    /**
     * True while editing a super admin — their adminness is owned by the
     * super-admin toggle, so the role selector is read-only for them.
     */
    public bool $roleLocked = false;

    /** @var list<string> Selected application module names (Read access). */
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
     * Users must be created from the MAIN database — that's the only place a
     * login lives. Creating one while switched inside a workspace would make an
     * account that can't sign in (the footgun this guards against).
     */
    private function onMain(): bool
    {
        if (! Schema::hasTable('workspaces')) {
            return true;
        }

        return app(WorkspaceManager::class)->current()->is_main;
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

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $userId = $this->editingId;

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('users', 'name')->ignore($userId)],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            // Password required when creating; optional (blank = keep) when editing.
            'password' => [$userId === null ? 'required' : 'nullable', 'string', 'min:8', 'max:255'],
            'role' => ['required', Rule::in(['staff', 'admin'])],
            'apps' => ['array'],
            'apps.*' => ['string'],
            // At least one database when creating — unless the account is locked
            // to a single workspace, which uses its own picker below.
            'workspaces' => ($userId === null && ! $this->lockToWorkspace) ? ['array', 'min:1'] : ['array'],
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
        ];
    }

    /**
     * Load an existing user into the form for editing (name / email / current
     * app grants). Password is left blank — only written if a new one is typed.
     */
    public function editUser(int $id): void
    {
        $this->guardAdmin();

        $user = User::query()->find($id);
        if ($user === null || ! $this->actorCanManage($user)) {
            return;
        }

        $this->editingId = (int) $user->getKey();
        $this->name = (string) $user->name;
        $this->email = (string) $user->email;
        $this->password = '';
        $this->role = $user->isAdmin() ? 'admin' : 'staff';
        $this->roleLocked = $user->isSuperAdmin();
        $this->apps = $this->currentApps($user);
        $this->workspaces = [];
        $this->lockToWorkspace = false;
        $this->lockWorkspaceId = null;
        $this->resetValidation();
    }

    public function cancelEdit(): void
    {
        $this->reset(['editingId', 'name', 'email', 'password', 'role', 'roleLocked', 'apps', 'workspaces', 'lockToWorkspace', 'lockWorkspaceId']);
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

        // Creating a user is only valid on Main (the login store). Inside a
        // workspace it would make a login-less account, so refuse it there.
        if ($this->editingId === null && ! $this->onMain()) {
            $this->addError('name', __('Switch to the Main database to add users — a user created inside a workspace can’t sign in.'));

            return;
        }

        $this->validate();

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
                $this->password,
                (int) $this->lockWorkspaceId,
                superAdmin: true,
            );
        } else {
            app(UserProvisioner::class)->provision(
                trim($this->name),
                $email,
                $this->password,
                array_values($this->apps),
                array_map('intval', array_values($this->workspaces)),
                $this->role === 'admin',
            );
        }

        app(\App\Erp\Activity\ActivityLogger::class)->log('user_created', $email, __('Created :name', ['name' => trim($this->name)]));

        $this->reset(['name', 'email', 'password', 'role', 'roleLocked', 'apps', 'workspaces', 'lockToWorkspace', 'lockWorkspaceId']);
        session()->flash('user_saved', __('User created.'));
    }

    /**
     * Update the edited user on the current (Main) database. Never changes the
     * admin flag; app grants are only (re)written for non-admins (admins bypass
     * ACLs entirely).
     */
    private function updateExisting(): void
    {
        $user = User::query()->find($this->editingId);
        if ($user === null || ! $this->actorCanManage($user)) {
            return;
        }

        $user->name = trim($this->name);
        $user->email = strtolower(trim($this->email));
        if ($this->password !== '') {
            $user->password = Hash::make($this->password);
        }

        // Role change — never for a super admin (owned by the super-admin
        // toggle), and never demote yourself or the last admin out of admin.
        if (! $user->isSuperAdmin()) {
            $wantsAdmin = $this->role === 'admin';
            $isSelf = $user->getKey() === Auth::id();
            $isLastAdmin = $user->isAdmin() && User::query()->where('is_admin', true)->count() <= 1;
            if (! $wantsAdmin && ($isSelf || $isLastAdmin)) {
                $wantsAdmin = true;
            }
            $user->is_admin = $wantsAdmin;
        }

        $user->save();

        // Admins bypass the ACL — grants only matter (and are rebuilt) for staff.
        if (! $user->isAdmin()) {
            app(UserProvisioner::class)->grantApps($user, array_values($this->apps));
        }

        app(\App\Erp\Activity\ActivityLogger::class)->log('user_updated', (string) $user->email, __('Updated :name', ['name' => (string) $user->name]));

        $this->reset(['editingId', 'name', 'email', 'password', 'role', 'roleLocked', 'apps', 'workspaces']);
        session()->flash('user_saved', __('User updated.'));
    }

    public function deleteUser(int $id): void
    {
        $this->guardAdmin();

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
     * Promote or demote another user to super admin. Owner-only: just a super
     * admin can do this (so the tier can't be self-granted by a regular admin).
     * Promoting also raises `is_admin` (super admin is a superset); you can't
     * demote yourself or the last super admin.
     */
    public function toggleSuperAdmin(int $id): void
    {
        $this->guardAdmin();
        abort_unless($this->actorIsSuperAdmin(), 403);

        $target = User::query()->find($id);
        if ($target === null || $target->getKey() === Auth::id()) {
            return;
        }

        if ($target->isSuperAdmin()) {
            // Demote — but never strip the last super admin.
            if (User::query()->where('is_super_admin', true)->count() <= 1) {
                return;
            }
            $target->is_super_admin = false;
            $target->save();
            app(\App\Erp\Activity\ActivityLogger::class)->log('user_updated', (string) $target->email, __('Removed super admin from :name', ['name' => (string) $target->name]));

            return;
        }

        $target->is_admin = true;
        $target->is_super_admin = true;
        $target->save();
        app(\App\Erp\Activity\ActivityLogger::class)->log('user_updated', (string) $target->email, __('Made :name a super admin', ['name' => (string) $target->name]));
    }

    /**
     * Grant or revoke the Accountant role (may confirm payments). Owner-only —
     * a sensitive financial power, so only a super admin can assign it, never a
     * regular admin and never yourself.
     */
    public function toggleAccountant(int $id): void
    {
        $this->guardAdmin();
        abort_unless($this->actorIsSuperAdmin(), 403);

        $target = User::query()->find($id);
        if ($target === null || $target->getKey() === Auth::id()) {
            return;
        }

        $target->is_accountant = ! $target->isAccountant();
        $target->save();

        app(\App\Erp\Activity\ActivityLogger::class)->log(
            'user_updated',
            (string) $target->email,
            $target->is_accountant
                ? __('Made :name an accountant', ['name' => (string) $target->name])
                : __('Removed accountant from :name', ['name' => (string) $target->name]),
        );
    }

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
            default => null,
        };
    }

    private function confirmedUpdate(): void
    {
        $this->validate();
        $this->updateExisting();
    }

    private function confirmedDelete(int $id): void
    {
        if ($this->canDelete($id)) {
            $this->performDelete($id);
        }
    }

    public function render(): View
    {
        /** @var \Illuminate\Support\Collection<int, IrModule> $apps */
        $apps = IrModule::query()
            ->where('application', true)
            ->where('state', ModuleState::Installed)
            ->orderBy('sequence')
            ->get();

        // All databases (Main + tenants) — Main is no longer implicit, the
        // admin picks it like any other.
        $workspaces = Schema::hasTable('workspaces')
            ? app(WorkspaceManager::class)->all()
            : collect();

        return view('livewire.settings.user-manager', [
            // NB: keys must NOT clash with the public $apps / $workspaces
            // properties (Livewire injects those into the view too).
            'appModules' => $apps,
            'workspaceList' => $workspaces,
            // Id → name for the "locked to …" tag in the list.
            'workspaceNames' => $workspaces->pluck('name', 'id'),
            'users' => User::query()->orderByDesc('is_super_admin')->orderByDesc('is_admin')->orderBy('name')->get(['id', 'name', 'email', 'is_admin', 'is_super_admin', 'is_accountant', 'home_workspace_id']),
            'currentUserId' => Auth::id(),
            'adminCount' => User::query()->where('is_admin', true)->count(),
            'actorIsSuperAdmin' => $this->actorIsSuperAdmin(),
            'onMain' => $this->onMain(),
        ]);
    }
}
