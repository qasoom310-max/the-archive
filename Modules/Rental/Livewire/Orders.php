<?php

declare(strict_types=1);

namespace Modules\Rental\Livewire;

use App\Erp\Security\Permission;
use App\Livewire\Concerns\GuardsModelAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
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
    use GuardsModelAccess;
    use WithPagination;

    /** all | draft | active | closed | cancelled */
    #[Url]
    public string $tab = 'all';

    protected function accessModelKey(): string
    {
        return 'rental.order';
    }

    public function mount(): void
    {
        $this->guardAccess(Permission::Read);
    }

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

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

    /**
     * Orders that still owe money — unpaid or part-paid on a live / closed order
     * (matches the dashboard money box). Draft and cancelled orders are excluded.
     *
     * @param  Builder<RentalOrder>  $query
     * @return Builder<RentalOrder>
     */
    private function applyOwing(Builder $query): Builder
    {
        return $query
            ->whereIn('payment_status', [RentalOrder::PAYMENT_UNPAID, RentalOrder::PAYMENT_PARTIAL])
            ->whereIn('state', [RentalOrder::STATE_ACTIVE, RentalOrder::STATE_CLOSED]);
    }

    public function render(): View
    {
        $query = RentalOrder::query()
            ->with(['customer:id,name,country', 'vehicle:id,name,plate_no,color', 'createdBy:id,name'])
            ->orderByDesc('id');

        if ($this->tab === 'unpaid') {
            $this->applyOwing($query);
        } elseif (in_array($this->tab, [
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

        $term = trim($this->search);
        if ($term !== '') {
            $like = '%' . $term . '%';
            $query->where(function ($q) use ($like): void {
                $q->where('reference', 'like', $like)
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', $like))
                    ->orWhereHas('vehicle', fn ($v) => $v->where('name', 'like', $like)->orWhere('plate_no', 'like', $like));
            });
        }

        $counts = RentalOrder::query()
            ->selectRaw('state, COUNT(*) as aggregate')
            ->groupBy('state')
            ->pluck('aggregate', 'state');

        $unpaidCount = $this->applyOwing(RentalOrder::query())->count();

        return view('rental::orders', [
            'orders' => $query->paginate(20),
            'counts' => $counts,
            'unpaidCount' => $unpaidCount,
            'totalCount' => (int) $counts->sum(),
        ]);
    }
}
