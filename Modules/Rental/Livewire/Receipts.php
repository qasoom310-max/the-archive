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
use Modules\Rental\Models\RentalReceipt;
use Modules\Rental\Services\RentalReceiptRows;

/**
 * Rental receipts list — "all receipts" + "find receipt" (search by reference
 * or customer name).
 */
#[Layout('components.layouts.app')]
#[Title('Receipts')]
final class Receipts extends Component
{
    use GuardsModelAccess;
    use SelectsListRows;
    use WithPagination;

    #[Url]
    public string $search = '';

    protected function accessModelKey(): string
    {
        return 'rental.receipt';
    }

    public function mount(): void
    {
        $this->guardAccess(Permission::Read);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
        // A tick made against one search is not a tick against the next.
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
        return app(RentalReceiptRows::class)->query($this->search)
            ->forPage($this->getPage(), 20)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    public function render(): View
    {
        // The same builder the export reads, so the screen and the download
        // can never disagree about which rows are in scope.
        $query = app(RentalReceiptRows::class)->query($this->search);

        $total = (float) RentalReceipt::query()->sum('amount');
        $user = Auth::user();

        return view('rental::receipts', [
            'receipts' => $query->paginate(20),
            'collectedTotal' => $total,
            'canManage' => $user instanceof User && $user->canApproveMaintenance(),
        ]);
    }
}
