<?php

declare(strict_types=1);

namespace Modules\Limousine\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Limousine\Models\LimoInvoice;

#[Layout('components.layouts.app')]
#[Title('Invoices')]
final class Invoices extends Component
{
    use WithPagination;

    #[Url]
    public string $tab = 'all';

    public function updatedTab(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $query = LimoInvoice::query()->with('customer:id,name')->orderByDesc('id');

        if (in_array($this->tab, [LimoInvoice::STATUS_UNPAID, LimoInvoice::STATUS_PARTIAL, LimoInvoice::STATUS_PAID], true)) {
            $query->where('status', $this->tab);
        }

        $counts = LimoInvoice::query()->selectRaw('status, COUNT(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');

        return view('limousine::invoices', [
            'invoices' => $query->paginate(20),
            'counts' => $counts,
            'totalCount' => (int) $counts->sum(),
        ]);
    }
}
