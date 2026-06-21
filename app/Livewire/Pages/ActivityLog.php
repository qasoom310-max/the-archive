<?php

declare(strict_types=1);

namespace App\Livewire\Pages;

use App\Models\ActivityLog as ActivityLogModel;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
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

    public function mount(): void
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
        if (! Schema::hasTable('activity_logs')) {
            return view('livewire.pages.activity-log', [
                'logs' => new LengthAwarePaginator([], 0, 30),
                'actions' => [],
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
        ]);
    }
}
