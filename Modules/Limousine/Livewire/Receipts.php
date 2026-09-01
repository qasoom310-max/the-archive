<?php

declare(strict_types=1);

namespace Modules\Limousine\Livewire;

use App\Erp\Security\Permission;
use App\Livewire\Concerns\GuardsModelAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Limousine\Models\LimoReceipt;

#[Layout('components.layouts.app')]
#[Title('Receipts')]
final class Receipts extends Component
{
    use GuardsModelAccess;
    use WithPagination;

    #[Url]
    public string $search = '';

    protected function accessModelKey(): string
    {
        return 'limousine.receipt';
    }

    public function mount(): void
    {
        $this->guardAccess(Permission::Read);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $query = LimoReceipt::query()
            ->with(['customer:id,name', 'invoice:id,reference'])
            ->orderByDesc('id');

        $term = trim($this->search);
        if ($term !== '') {
            $query->where(function ($q) use ($term): void {
                $q->where('reference', 'like', "%{$term}%")
                    ->orWhereHas('customer', function ($c) use ($term): void {
                        $c->where('name', 'like', "%{$term}%");
                    });
            });
        }

        return view('limousine::receipts', [
            'receipts' => $query->paginate(20),
            // Money taken on a booking issues its own receipt, so a hand-made
            // one is a correction reserved for the owner. Same rule server-side
            // in ReceiptForm — hiding the button alone would only be cosmetic.
            'canCreateManually' => Auth::user()?->isSuperAdmin() ?? false,
            'collectedTotal' => (float) LimoReceipt::query()->sum('amount'),
        ]);
    }
}
