<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Erp\Admin\UserProvisioner;
use App\Erp\Enums\ModuleState;
use App\Erp\Tenancy\WorkspaceManager;
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
    /** Set while editing an existing user; null in create mode. */
    public ?int $editingId = null;

    public string $name = '';

    public string $email = '';

    public string $password = '';

    /** @var list<string> Selected application module names (Read access). */
    public array $apps = [];

    /** @var list<int> Selected workspace ids to create the account in (create mode). */
    public array $workspaces = [];

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
            'apps' => ['array'],
            'apps.*' => ['string'],
            // At least one database when creating (Main is no longer implicit).
            'workspaces' => $userId === null ? ['array', 'min:1'] : ['array'],
            'workspaces.*' => ['integer'],
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
        if ($user === null) {
            return;
        }

        $this->editingId = (int) $user->getKey();
        $this->name = (string) $user->name;
        $this->email = (string) $user->email;
        $this->password = '';
        $this->apps = $this->currentApps($user);
        $this->workspaces = [];
        $this->resetValidation();
    }

    public function cancelEdit(): void
    {
        $this->reset(['editingId', 'name', 'email', 'password', 'apps', 'workspaces']);
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

        if ($this->editingId !== null) {
            $this->updateExisting();

            return;
        }

        $email = strtolower(trim($this->email));

        app(UserProvisioner::class)->provision(
            trim($this->name),
            $email,
            $this->password,
            array_values($this->apps),
            array_map('intval', array_values($this->workspaces)),
        );

        app(\App\Erp\Activity\ActivityLogger::class)->log('user_created', $email, __('Created :name', ['name' => trim($this->name)]));

        $this->reset(['name', 'email', 'password', 'apps', 'workspaces']);
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
        if ($user === null) {
            return;
        }

        $user->name = trim($this->name);
        $user->email = strtolower(trim($this->email));
        if ($this->password !== '') {
            $user->password = Hash::make($this->password);
        }
        $user->save();

        if (! $user->isAdmin()) {
            app(UserProvisioner::class)->grantApps($user, array_values($this->apps));
        }

        app(\App\Erp\Activity\ActivityLogger::class)->log('user_updated', (string) $user->email, __('Updated :name', ['name' => (string) $user->name]));

        $this->reset(['editingId', 'name', 'email', 'password', 'apps', 'workspaces']);
        session()->flash('user_saved', __('User updated.'));
    }

    public function deleteUser(int $id): void
    {
        $this->guardAdmin();

        $target = User::query()->find($id);
        if ($target === null) {
            return;
        }

        // Never delete yourself, nor the last remaining admin.
        if ($target->getKey() === Auth::id()) {
            return;
        }
        if ($target->isAdmin() && User::query()->where('is_admin', true)->count() <= 1) {
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
            'users' => User::query()->orderByDesc('is_admin')->orderBy('name')->get(['id', 'name', 'email', 'is_admin']),
            'currentUserId' => Auth::id(),
            'adminCount' => User::query()->where('is_admin', true)->count(),
        ]);
    }
}
