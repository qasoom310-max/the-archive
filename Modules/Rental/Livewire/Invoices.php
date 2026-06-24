<?php

declare(strict_types=1);

namespace Modules\Rental\Livewire;

use Illuminate\Contracts\View\View;
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
    use WithPagination;

    /** all | unpaid | partial | paid */
    #[Url]
    public string $tab = 'all';

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

        return view('rental::invoices', [
            'invoices' => $query->paginate(20),
            'counts' => $counts,
            'totalCount' => (int) $counts->sum(),
        ]);
    }
}
