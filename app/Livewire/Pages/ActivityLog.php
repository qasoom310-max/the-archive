<?php

declare(strict_types=1);

namespace App\Livewire\Pages;

use App\Erp\Activity\ActivityLogger;
use App\Erp\Backup\DatabaseBackup;
use App\Models\ActivityLog as ActivityLogModel;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Admin-only audit trail: every recorded action (sign-ins, record
 * create/update/delete, user management, settings changes) with who did it
 * and when. Read-only — entries are immutable. Scoped to the current database
 * (each workspace has its own activity_logs table).
 */
#[Layout('components.layouts.app')]
#[Title('Activity log')]
final class ActivityLog extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $action = '';

    /** Snapshot pending a password-confirmed restore (null = no modal open). */
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

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedAction(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'action']);
        $this->resetPage();
    }

    // ── Backups (this database's daily snapshots) ──────────────────────────

    public function backupNow(): void
    {
        $this->guardAdmin();

        app(DatabaseBackup::class)->snapshot();
        app(ActivityLogger::class)->log('backup_created', 'Database backup', __('Created a manual backup.'));
        session()->flash('activity_toast', __('Backup created.'));
    }

    public function confirmRestore(string $path): void
    {
        $this->guardAdmin();

        if (! app(DatabaseBackup::class)->owns($path)) {
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
        if (! $user instanceof User || $this->restorePath === null) {
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

        // The whole database was rewritten — full reload so every screen reflects it.
        session()->flash('activity_toast', __('Database restored from :name.', ['name' => $name]));
        $this->redirect(route('activity'), navigate: false);
    }

    public function deleteBackup(string $path): void
    {
        $this->guardAdmin();
        app(DatabaseBackup::class)->delete($path);
    }

    /**
     * @return Builder<ActivityLogModel>
     */
    private function baseQuery(): Builder
    {
        return ActivityLogModel::query()
            ->when($this->action !== '', fn (Builder $q): Builder => $q->where('action', $this->action))
            ->when($this->search !== '', function (Builder $q): Builder {
                $term = '%' . $this->search . '%';

                return $q->where(function (Builder $inner) use ($term): void {
                    $inner->where('user_name', 'like', $term)
                        ->orWhere('subject', 'like', $term)
                        ->orWhere('description', 'like', $term)
                        ->orWhere('ip_address', 'like', $term);
                });
            });
    }

    public function render(): View
    {
        // A workspace database provisioned before this feature won't have the
        // table yet (core migrations only auto-apply to Main). Degrade to an
        // empty state instead of 500ing until that DB is migrated.
        $backup = app(DatabaseBackup::class);

        if (! Schema::hasTable('activity_logs')) {
            return view('livewire.pages.activity-log', [
                'logs' => new LengthAwarePaginator([], 0, 30),
                'actions' => [],
                'backups' => $backup->list(),
                'retentionDays' => DatabaseBackup::RETENTION_DAYS,
            ]);
        }

        $logs = $this->baseQuery()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(30);

        // Distinct action codes present, for the filter dropdown.
        $actions = ActivityLogModel::query()
            ->select('action')
            ->distinct()
            ->orderBy('action')
            ->pluck('action')
            ->all();

        return view('livewire.pages.activity-log', [
            'logs' => $logs,
            'actions' => $actions,
            'backups' => $backup->list(),
            'retentionDays' => DatabaseBackup::RETENTION_DAYS,
        ]);
    }
}
