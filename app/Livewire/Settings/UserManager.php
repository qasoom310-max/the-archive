<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Erp\Admin\UserProvisioner;
use App\Erp\Enums\ModuleState;
use App\Erp\Tenancy\WorkspaceManager;
use App\Models\Ir\IrModule;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Admin-only "Users" tab in Settings: create a staff account (name / email /
 * password) and grant it **view-only** access to a chosen set of apps and
 * databases. Embedded inside the Settings page under its own tab.
 *
 * Every action re-checks the admin gate (defence in depth against a crafted
 * Livewire payload) — the tab is only rendered for admins, but the component
 * never trusts that alone.
 */
final class UserManager extends Component
{
    public string $name = '';

    public string $email = '';

    public string $password = '';

    /** @var list<string> Selected application module names (Read access). */
    public array $apps = [];

    /** @var list<int> Selected tenant workspace ids to also provision into. */
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
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('users', 'name')],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'apps' => ['array'],
            'apps.*' => ['string'],
            'workspaces' => ['array'],
            'workspaces.*' => ['integer'],
        ];
    }

    public function createUser(): void
    {
        $this->guardAdmin();
        $this->validate();

        app(UserProvisioner::class)->provision(
            trim($this->name),
            strtolower(trim($this->email)),
            $this->password,
            array_values($this->apps),
            array_map('intval', array_values($this->workspaces)),
        );

        $this->reset(['name', 'email', 'password', 'apps', 'workspaces']);

        session()->flash('user_created', __('User created.'));
    }

    public function deleteUser(int $id): void
    {
        $this->guardAdmin();

        $target = User::query()->find($id);

        // Never delete an admin or yourself from here.
        if ($target === null || $target->isAdmin() || $target->getKey() === Auth::id()) {
            return;
        }

        app(UserProvisioner::class)->deleteUser($target);
    }

    public function render(): View
    {
        /** @var \Illuminate\Support\Collection<int, IrModule> $apps */
        $apps = IrModule::query()
            ->where('application', true)
            ->where('state', ModuleState::Installed)
            ->orderBy('sequence')
            ->get();

        // Tenant ("other") databases the new account can also be provisioned
        // into. Main is implicit — the account always lives there.
        $workspaces = Schema::hasTable('workspaces')
            ? app(WorkspaceManager::class)->all()->where('is_main', false)->values()
            : collect();

        // NB: keys must NOT clash with the public $apps / $workspaces
        // properties (Livewire injects those into the view too).
        return view('livewire.settings.user-manager', [
            'appModules' => $apps,
            'workspaceList' => $workspaces,
            'users' => User::query()
                ->where('is_admin', false)
                ->orderBy('name')
                ->get(['id', 'name', 'email']),
        ]);
    }
}
