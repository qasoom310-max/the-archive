<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Erp\Tenancy\WorkspaceManager;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * The database manager (Odoo-style) reached from the topbar "My database"
 * menu item. Admin-only: list workspaces, create a new isolated database
 * (fully provisioned with every feature), switch into one, or delete it.
 *
 * Creating runs synchronously — provisioning migrates + installs every module
 * into a fresh SQLite file, which takes a few seconds; the button shows a
 * working state meanwhile.
 */
#[Layout('components.layouts.app')]
#[Title('My databases')]
final class WorkspacesPage extends Component
{
    #[Validate('required|string|max:80')]
    public string $newName = '';

    /** Workspace currently being renamed inline (null = none). */
    public ?int $editingId = null;

    public string $editName = '';

    public function mount(): void
    {
        abort_unless($this->isAdmin(), 403);
    }

    private function isAdmin(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->isAdmin();
    }

    public function create(): void
    {
        abort_unless($this->isAdmin(), 403);
        $this->validate();

        $user = Auth::user();
        if (! $user instanceof User) {
            return;
        }

        app(WorkspaceManager::class)->provision(trim($this->newName), $user);

        $this->newName = '';
        session()->flash('workspace_status', __('Database created. Switch to it from the list.'));
    }

    public function startRename(int $id): void
    {
        abort_unless($this->isAdmin(), 403);

        $workspace = app(WorkspaceManager::class)->find($id);
        if ($workspace === null) {
            return;
        }

        $this->editingId = $id;
        $this->editName = $workspace->name;
        $this->resetErrorBag('editName');
    }

    public function cancelRename(): void
    {
        $this->editingId = null;
        $this->editName = '';
        $this->resetErrorBag('editName');
    }

    public function rename(): void
    {
        abort_unless($this->isAdmin(), 403);

        if ($this->editingId === null) {
            return;
        }

        $this->validate(['editName' => 'required|string|max:80']);

        $workspace = app(WorkspaceManager::class)->find($this->editingId);
        if ($workspace !== null) {
            $workspace->name = trim($this->editName);
            $workspace->save();
        }

        $this->editingId = null;
        $this->editName = '';
        session()->flash('workspace_status', __('Database renamed.'));
    }

    public function deleteWorkspace(int $id): void
    {
        abort_unless($this->isAdmin(), 403);

        $manager = app(WorkspaceManager::class);
        $workspace = $manager->find($id);

        if ($workspace !== null && ! $workspace->is_main) {
            $manager->delete($workspace);
        }
    }

    public function render(): View
    {
        $manager = app(WorkspaceManager::class);

        return view('livewire.pages.workspaces', [
            'workspaces' => $manager->all(),
            'currentId' => $manager->current()->id,
        ]);
    }
}
