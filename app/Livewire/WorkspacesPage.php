<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Erp\Tenancy\WorkspaceManager;
use App\Livewire\Concerns\ConfirmsWithEmailOtp;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
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
    use ConfirmsWithEmailOtp;
    use \App\Livewire\Concerns\HasAdminCheck;

    #[Validate('required|string|max:80')]
    public string $newName = '';

    /** Workspace currently being renamed inline (null = none). */
    public ?int $editingId = null;

    public string $editName = '';

    /** Workspace whose delete-confirmation (password) modal is open (null = none). */
    public ?int $deletingId = null;

    public string $deletePassword = '';

    public function mount(): void
    {
        abort_unless($this->isAdmin(), 403);
        // A locked user runs inside one workspace only — they are not a
        // landlord and may not create, switch or delete databases.
        abort_if(Auth::user()?->isLockedToWorkspace() === true, 403);
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

        // Editing a database is sensitive — a regular admin confirms an emailed
        // code first (super admin is exempt).
        if (! $this->requireOtp('workspace.rename', ['id' => $this->editingId, 'name' => trim($this->editName)])) {
            return;
        }

        $this->performRename($this->editingId, trim($this->editName));
    }

    private function performRename(int $id, string $name): void
    {
        $workspace = app(WorkspaceManager::class)->find($id);
        if ($workspace !== null) {
            $workspace->name = $name;
            $workspace->save();
        }

        $this->editingId = null;
        $this->editName = '';
        session()->flash('workspace_status', __('Database renamed.'));
    }

    public function confirmDelete(int $id): void
    {
        abort_unless($this->isAdmin(), 403);

        $this->deletingId = $id;
        $this->deletePassword = '';
        $this->resetErrorBag('deletePassword');
    }

    public function cancelDelete(): void
    {
        $this->deletingId = null;
        $this->deletePassword = '';
        $this->resetErrorBag('deletePassword');
    }

    /**
     * Confirm deletion with the admin's password, then move the database to
     * trash (soft delete) — restorable for {@see WorkspaceManager::RETENTION_DAYS}
     * days, after which the daily sweep purges it for good.
     */
    public function deleteWorkspace(): void
    {
        abort_unless($this->isAdmin(), 403);

        if ($this->deletingId === null) {
            return;
        }

        $user = Auth::user();
        if (! $user instanceof User) {
            return;
        }

        if ($this->deletePassword === '' || ! Hash::check($this->deletePassword, (string) $user->password)) {
            $this->addError('deletePassword', __('Incorrect password.'));

            return;
        }

        // Close the password dialog and, for a regular admin, require the
        // emailed code on top of the password (super admin is exempt).
        $id = $this->deletingId;
        $this->deletingId = null;
        $this->deletePassword = '';

        if (! $this->requireOtp('workspace.delete', ['id' => $id])) {
            return;
        }

        $this->performTrash($id);
    }

    private function performTrash(int $id): void
    {
        $manager = app(WorkspaceManager::class);
        $workspace = $manager->find($id);

        if ($workspace !== null && ! $workspace->is_main) {
            $manager->trash($workspace);
        }

        session()->flash('workspace_status', __('Database moved to trash. You can restore it within :days days.', ['days' => WorkspaceManager::RETENTION_DAYS]));
    }

    /**
     * Run the action the email-OTP just confirmed (regular-admin path).
     *
     * @param array<string, mixed> $args
     */
    protected function runConfirmedAction(string $action, array $args): void
    {
        abort_unless($this->isAdmin(), 403);

        match ($action) {
            'workspace.rename' => $this->performRename((int) ($args['id'] ?? 0), (string) ($args['name'] ?? '')),
            'workspace.delete' => $this->performTrash((int) ($args['id'] ?? 0)),
            default => null,
        };
    }

    public function restoreWorkspace(int $id): void
    {
        abort_unless($this->isAdmin(), 403);

        $manager = app(WorkspaceManager::class);
        $workspace = $manager->findAny($id);

        if ($workspace !== null && $workspace->trashed()) {
            $manager->restore($workspace);
            session()->flash('workspace_status', __('Database restored.'));
        }
    }

    public function render(): View
    {
        $manager = app(WorkspaceManager::class);

        return view('livewire.pages.workspaces', [
            'workspaces' => $manager->all(),
            'trashed' => $manager->trashed(),
            'currentId' => $manager->current()->id,
            'retentionDays' => WorkspaceManager::RETENTION_DAYS,
        ]);
    }
}
