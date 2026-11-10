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
use Modules\Rental\Models\RentalInvoice;
use Modules\Rental\Services\RentalInvoiceRows;

/**
 * Rental invoices list — status tabs over "view invoices".
 */
#[Layout('components.layouts.app')]
#[Title('Invoices')]
final class Invoices extends Component
{
    use GuardsModelAccess;
    use SelectsListRows;
    use WithPagination;

    /** all | unpaid | partial | paid */
    #[Url]
    public string $tab = 'all';

    /** Customer name (or invoice number) typed in the search box. */
    #[Url(except: '')]
    public string $search = '';

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
        $this->clearSelection();
    }

    public function updatedSearch(): void
    {
        // A tick made against one search is not a tick against the next.
        $this->resetPage();
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
        return app(RentalInvoiceRows::class)->query($this->tab, $this->search)
            ->forPage($this->getPage(), 20)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    public function render(): View
    {
        $rows = app(RentalInvoiceRows::class);
        $query = $rows->query($this->tab, $this->search);

        // The tab counts follow the search, so "Unpaid 3" means this customer's.
        $counts = RentalInvoice::query()
            ->tap(fn ($q) => $rows->search($q, $this->search))
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
