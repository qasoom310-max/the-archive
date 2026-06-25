<?php

declare(strict_types=1);

namespace Modules\Rental\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Rental\Models\RentalOrder;

/**
 * Rental orders list — one page covering "new / active / closed / all orders
 * and search by date" via status tabs and a pick-up date range filter.
 */
#[Layout('components.layouts.app')]
#[Title('Orders')]
final class Orders extends Component
{
    use WithPagination;

    /** all | draft | active | closed | cancelled */
    #[Url]
    public string $tab = 'all';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public function updatedTab(): void
    {
        $this->resetPage();
    }

    public function updatedFrom(): void
    {
        $this->resetPage();
    }

    public function updatedTo(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $query = RentalOrder::query()
            ->with(['customer:id,name', 'vehicle:id,name,plate_no,color'])
            ->orderByDesc('id');

        if (in_array($this->tab, [
            RentalOrder::STATE_DRAFT,
            RentalOrder::STATE_ACTIVE,
            RentalOrder::STATE_CLOSED,
            RentalOrder::STATE_CANCELLED,
        ], true)) {
            $query->where('state', $this->tab);
        }

        if ($this->from !== '') {
            $query->whereDate('start_date', '>=', $this->from);
        }
        if ($this->to !== '') {
            $query->whereDate('start_date', '<=', $this->to);
        }

        $counts = RentalOrder::query()
            ->selectRaw('state, COUNT(*) as aggregate')
            ->groupBy('state')
            ->pluck('aggregate', 'state');

        return view('rental::orders', [
            'orders' => $query->paginate(20),
            'counts' => $counts,
            'totalCount' => (int) $counts->sum(),
        ]);
    }
}
