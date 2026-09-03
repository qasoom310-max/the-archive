<?php

declare(strict_types=1);

namespace Modules\Rental\Livewire;

use App\Erp\Security\Permission;
use App\Livewire\Concerns\GuardsModelAccess;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Rental\Models\RentalQuotation;

/**
 * Rental quotations list — status tabs over "view quotations".
 */
#[Layout('components.layouts.app')]
#[Title('Quotations')]
final class Quotations extends Component
{
    use GuardsModelAccess;
    use WithPagination;

    /** all | draft | sent | accepted | declined | converted */
    #[Url]
    public string $tab = 'all';

    protected function accessModelKey(): string
    {
        return 'rental.quotation';
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
        $query = RentalQuotation::query()
            ->with(['customer:id,name', 'vehicle:id,name,plate_no,color'])
            ->orderByDesc('id');

        if (in_array($this->tab, [
            RentalQuotation::STATUS_DRAFT,
            RentalQuotation::STATUS_SENT,
            RentalQuotation::STATUS_ACCEPTED,
            RentalQuotation::STATUS_DECLINED,
            RentalQuotation::STATUS_CONVERTED,
        ], true)) {
            $query->where('status', $this->tab);
        }

        $counts = RentalQuotation::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $user = Auth::user();

        return view('rental::quotations', [
            'quotations' => $query->paginate(20),
            'counts' => $counts,
            'totalCount' => (int) $counts->sum(),
            'canManage' => $user instanceof User && $user->canApproveMaintenance(),
        ]);
    }
}
