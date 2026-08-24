<?php

declare(strict_types=1);

namespace Modules\Rental\Livewire;

use App\Erp\Security\Permission;
use App\Livewire\Concerns\GuardsModelAccess;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Rental\Models\RentalReceipt;

/**
 * Rental receipts list — "all receipts" + "find receipt" (search by reference
 * or customer name).
 */
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
        return 'rental.receipt';
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
        $query = RentalReceipt::query()
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

        $total = (float) RentalReceipt::query()->sum('amount');

        return view('rental::receipts', [
            'receipts' => $query->paginate(20),
            'collectedTotal' => $total,
        ]);
    }
}
