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
use Modules\Rental\Models\RentalInvoice;

/**
 * Rental invoices list — status tabs over "view invoices".
 */
#[Layout('components.layouts.app')]
#[Title('Invoices')]
final class Invoices extends Component
{
    use GuardsModelAccess;
    use WithPagination;

    /** all | unpaid | partial | paid */
    #[Url]
    public string $tab = 'all';

    protected function accessModelKey(): string
    {
        return 'rental.invoice';
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
        $query = RentalInvoice::query()
            ->with('customer:id,name')
            ->orderByDesc('id');

        if (in_array($this->tab, [
            RentalInvoice::STATUS_UNPAID,
            RentalInvoice::STATUS_PARTIAL,
            RentalInvoice::STATUS_PAID,
        ], true)) {
            $query->where('status', $this->tab);
        }

        $counts = RentalInvoice::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $user = Auth::user();

        return view('rental::invoices', [
            'invoices' => $query->paginate(20),
            'counts' => $counts,
            'totalCount' => (int) $counts->sum(),
            'canManage' => $user instanceof User && $user->canApproveMaintenance(),
        ]);
    }
}
