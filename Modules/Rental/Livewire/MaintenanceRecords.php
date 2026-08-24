<?php

declare(strict_types=1);

namespace Modules\Rental\Livewire;

use App\Erp\Security\Permission;
use App\Livewire\Concerns\GuardsModelAccess;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Rental\Models\RentalMaintenance;

/**
 * Vehicle maintenance records — "all records" with a status filter.
 */
#[Layout('components.layouts.app')]
#[Title('Maintenance')]
final class MaintenanceRecords extends Component
{
    use GuardsModelAccess;
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
    }

    public function render(): View
    {
        $query = RentalMaintenance::query()
            ->with('vehicle:id,name,plate_no,color')
            ->orderByDesc('id');

        if (in_array($this->tab, [
            RentalMaintenance::STATUS_PENDING,
            RentalMaintenance::STATUS_APPROVED,
            RentalMaintenance::STATUS_IN_PROGRESS,
            RentalMaintenance::STATUS_DONE,
        ], true)) {
            $query->where('status', $this->tab);
        }

        $counts = RentalMaintenance::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return view('rental::maintenance', [
            'records' => $query->paginate(20),
            'counts' => $counts,
            'totalCount' => (int) $counts->sum(),
            'spendTotal' => (float) RentalMaintenance::query()->sum('cost'),
        ]);
    }
}
