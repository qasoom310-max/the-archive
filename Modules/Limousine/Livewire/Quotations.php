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
use Modules\Limousine\Models\LimoQuotation;

#[Layout('components.layouts.app')]
#[Title('Quotations')]
final class Quotations extends Component
{
    use GuardsModelAccess;
    use WithPagination;

    #[Url]
    public string $tab = 'all';

    protected function accessModelKey(): string
    {
        return 'limousine.quotation';
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
        $query = LimoQuotation::query()->with('customer:id,name')->orderByDesc('id');

        if (in_array($this->tab, [
            LimoQuotation::STATUS_DRAFT,
            LimoQuotation::STATUS_SENT,
            LimoQuotation::STATUS_ACCEPTED,
            LimoQuotation::STATUS_DECLINED,
            LimoQuotation::STATUS_CONVERTED,
        ], true)) {
            $query->where('status', $this->tab);
        }

        $counts = LimoQuotation::query()->selectRaw('status, COUNT(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');

        return view('limousine::quotations', [
            'quotations' => $query->paginate(20),
            'counts' => $counts,
            'totalCount' => (int) $counts->sum(),
        ]);
    }
}
