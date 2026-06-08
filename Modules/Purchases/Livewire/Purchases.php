<?php

declare(strict_types=1);

namespace Modules\Purchases\Livewire;

use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Vendor-bill browser — engine List view for `purchases.purchase`.
 */
#[Layout('components.layouts.app')]
#[Title('Purchases')]
final class Purchases extends Component
{
    public function render(): View
    {
        return view('purchases::purchases', [
            'canCreate' => app(AccessControl::class)
                ->allows(Auth::user(), 'purchases.purchase', Permission::Create),
        ]);
    }
}
