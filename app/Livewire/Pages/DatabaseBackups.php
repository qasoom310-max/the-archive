<?php

declare(strict_types=1);

namespace App\Livewire\Pages;

use App\Erp\Activity\ActivityLogger;
use App\Erp\Backup\DatabaseBackup;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Admin-only "Backups" — an in-app database time machine (like Hostinger's
 * backups). Lists the active database's daily snapshots, lets an admin take one
 * on demand, and RESTORE the whole database back to a snapshot (password-gated,
 * because it overwrites every table). Scoped to the current database — each
 * workspace manages only its own backups.
 */
#[Layout('components.layouts.app')]
#[Title('Backups')]
final class DatabaseBackups extends Component
{
    /** Snapshot pending a password-confirmed restore (null = no modal). */
    public ?string $restorePath = null;

    public string $restorePassword = '';

    public function mount(): void
    {
        $this->guardAdmin();
    }

    private function guardAdmin(): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->isAdmin(), 403);
    }

    public function backupNow(): void
    {
        $this->guardAdmin();

        $path = app(DatabaseBackup::class)->snapshot();

        app(ActivityLogger::class)->log('backup_created', 'Database backup', __('Created a manual backup.'));
        session()->flash('backup_toast', __('Backup created.'));
    }

    public function confirmRestore(string $path): void
    {
        $this->guardAdmin();

        $backup = app(DatabaseBackup::class);
        if (! $backup->owns($path)) {
            return;
        }

        $this->restorePath = $path;
        $this->restorePassword = '';
        $this->resetValidation();
    }

    public function cancelRestore(): void
    {
        $this->restorePath = null;
        $this->restorePassword = '';
        $this->resetValidation();
    }

    public function restore(): void
    {
        $this->guardAdmin();

        $user = Auth::user();
        if (! $user instanceof User) {
            return;
        }

        if ($this->restorePath === null) {
            return;
        }

        $backup = app(DatabaseBackup::class);
        if (! $backup->owns($this->restorePath)) {
            $this->cancelRestore();

            return;
        }

        // Overwriting every table is destructive — require the admin's password.
        if ($this->restorePassword === '' || ! Hash::check($this->restorePassword, (string) $user->password)) {
            $this->addError('restorePassword', __('Incorrect password.'));

            return;
        }

        $name = basename($this->restorePath);
        $backup->restore($this->restorePath);

        app(ActivityLogger::class)->log('backup_restored', 'Database backup ' . $name, __('Restored the database from a backup.'));

        $this->restorePath = null;
        $this->restorePassword = '';

        // The whole database was rewritten — reload so every screen reflects it.
        session()->flash('backup_toast', __('Database restored from :name.', ['name' => $name]));
        $this->redirect('/app/backups', navigate: false);
    }

    public function deleteBackup(string $path): void
    {
        $this->guardAdmin();
        app(DatabaseBackup::class)->delete($path);
    }

    public function render(): View
    {
        return view('livewire.pages.database-backups', [
            'backups' => app(DatabaseBackup::class)->list(),
            'retentionDays' => DatabaseBackup::RETENTION_DAYS,
        ]);
    }
}
