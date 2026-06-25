<?php

declare(strict_types=1);

namespace Modules\Rental\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Rental\Models\RentalReplacement;

/**
 * Car replacements list (active / closed).
 */
#[Layout('components.layouts.app')]
#[Title('Car replacements')]
final class Replacements extends Component
{
    use WithPagination;

    /** all | active | closed */
    #[Url]
    public string $tab = 'all';

    public function updatedTab(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $query = RentalReplacement::query()
            ->with(['customer:id,name', 'originalVehicle:id,name,plate_no,color', 'replacementVehicle:id,name,plate_no,color'])
            ->orderByDesc('id');

        if (in_array($this->tab, [RentalReplacement::STATUS_ACTIVE, RentalReplacement::STATUS_CLOSED], true)) {
            $query->where('status', $this->tab);
        }

        $counts = RentalReplacement::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return view('rental::replacements', [
            'replacements' => $query->paginate(20),
            'counts' => $counts,
            'totalCount' => (int) $counts->sum(),
        ]);
    }
}
