<?php

declare(strict_types=1);

namespace Modules\Rental\Livewire;

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
    use WithPagination;

    /** all | scheduled | in_progress | done */
    #[Url]
    public string $tab = 'all';

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
            RentalMaintenance::STATUS_SCHEDULED,
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
