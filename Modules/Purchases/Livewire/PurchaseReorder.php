<?php

declare(strict_types=1);

namespace Modules\Purchases\Livewire;

use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Purchases\Services\PurchaseReorderData;

/**
 * Reorder Report — the buying team's shopping list: purchasable items (resale
 * products + ingredients + condiments) at or below their minimum stock. A
 * read-only report off {@see PurchaseReorderData}; exportable as CSV.
 */
#[Layout('components.layouts.app')]
#[Title('Reorder Report')]
final class PurchaseReorder extends Component
{
    #[Url(except: '')]
    public string $search = '';

    public function mount(): void
    {
        app(AccessControl::class)->authorize(Auth::user(), 'purchases.purchase', Permission::Read);
    }

    public function render(): View
    {
        $data = app(PurchaseReorderData::class);

        return view('purchases::reorder', [
            'rows' => $data->rows($this->search),
            'summary' => $data->summary($this->search),
            'threshold' => $data->threshold(),
            'canCreate' => app(AccessControl::class)
                ->allows(Auth::user(), 'purchases.purchase', Permission::Create),
        ]);
    }
}
