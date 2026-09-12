<?php

declare(strict_types=1);

namespace Modules\Rental\Livewire;

use App\Erp\Security\Permission;
use App\Livewire\Concerns\GuardsModelAccess;
use App\Livewire\Concerns\SelectsListRows;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Rental\Models\RentalMaintenance;
use Modules\Rental\Services\RentalMaintenanceRows;

/**
 * Vehicle maintenance records — "all records" with a status filter.
 */
#[Layout('components.layouts.app')]
#[Title('Maintenance')]
final class MaintenanceRecords extends Component
{
    use GuardsModelAccess;
    use SelectsListRows;
    use WithPagination;

    /** all | pending | approved | in_progress | done */
    #[Url]
    public string $tab = 'all';

    protected function accessModelKey(): string
    {
        return 'rental.maintenance';
    }

    public function mount(): void
    {
        $this->guardAccess(Permission::Read);
    }

    public function updatedTab(): void
    {
        $this->resetPage();
        // A tick made against one tab is not a tick against another.
        $this->clearSelection();
    }

    /**
     * The ids on the page being looked at, for the header checkbox. Same
     * query and order as the list, so "select all on this page" means what
     * the eye sees.
     *
     * @return list<int>
     */
    protected function currentPageIds(): array
    {
        return app(RentalMaintenanceRows::class)->query($this->tab)
            ->forPage($this->getPage(), 20)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    public function render(): View
    {
        // The same builder the export reads, so the screen and the download
        // can never disagree about which rows are in scope.
        $query = app(RentalMaintenanceRows::class)->query($this->tab);

        $counts = RentalMaintenance::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $user = Auth::user();

        return view('rental::maintenance', [
            'records' => $query->paginate(20),
            'counts' => $counts,
            'totalCount' => (int) $counts->sum(),
            'spendTotal' => (float) RentalMaintenance::query()->sum('cost'),
            'canManage' => $user instanceof User && $user->canApproveMaintenance(),
        ]);
    }
}
