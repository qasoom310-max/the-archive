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
use Modules\Rental\Models\RentalReplacement;
use Modules\Rental\Services\RentalReplacementRows;

/**
 * Car replacements list (active / closed).
 */
#[Layout('components.layouts.app')]
#[Title('Car replacements')]
final class Replacements extends Component
{
    use GuardsModelAccess;
    use SelectsListRows;
    use WithPagination;

    /** all | active | closed */
    #[Url]
    public string $tab = 'all';

    protected function accessModelKey(): string
    {
        return 'rental.replacement';
    }

    public function mount(): void
    {
        $this->guardAccess(Permission::Read);
    }

    public function updatedTab(): void
    {
        $this->resetPage();
        // A tick made against one tab is not a tick against another.
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
        return app(RentalReplacementRows::class)->query($this->tab)
            ->forPage($this->getPage(), 20)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    public function render(): View
    {
        // The same builder the export reads, so the screen and the download
        // can never disagree about which rows are in scope.
        $query = app(RentalReplacementRows::class)->query($this->tab);

        $counts = RentalReplacement::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $user = Auth::user();

        return view('rental::replacements', [
            'replacements' => $query->paginate(20),
            'counts' => $counts,
            'totalCount' => (int) $counts->sum(),
            'canManage' => $user instanceof User && $user->canApproveMaintenance(),
        ]);
    }
}
