<?php

declare(strict_types=1);

namespace Modules\Limousine\Livewire;

use App\Erp\Security\Permission;
use App\Livewire\Concerns\GuardsModelAccess;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Limousine\Models\LimoBooking;

/**
 * Limousine bookings list — status tabs + a pick-up date range filter.
 */
#[Layout('components.layouts.app')]
#[Title('Bookings')]
final class Bookings extends Component
{
    use GuardsModelAccess;
    use WithPagination;

    /** all | queue | confirmed | active | completed | cancelled */
    #[Url]
    public string $tab = 'all';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    protected function accessModelKey(): string
    {
        return 'limousine.booking';
    }

    public function mount(): void
    {
        $this->guardAccess(Permission::Read);
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

    public function render(): View
    {
        $query = LimoBooking::query()
            ->with(['customer:id,name', 'pickupLocation:id,name', 'dropoffLocation:id,name'])
            ->orderByDesc('pickup_at');

        if (in_array($this->tab, [
            LimoBooking::STATUS_QUEUE,
            LimoBooking::STATUS_CONFIRMED,
            LimoBooking::STATUS_ACTIVE,
            LimoBooking::STATUS_COMPLETED,
            LimoBooking::STATUS_CANCELLED,
        ], true)) {
            $query->where('status', $this->tab);
        }

        if ($this->from !== '') {
            $query->whereDate('pickup_at', '>=', $this->from);
        }
        if ($this->to !== '') {
            $query->whereDate('pickup_at', '<=', $this->to);
        }

        $counts = LimoBooking::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return view('limousine::bookings', [
            'bookings' => $query->paginate(20),
            'counts' => $counts,
            'totalCount' => (int) $counts->sum(),
        ]);
    }
}
