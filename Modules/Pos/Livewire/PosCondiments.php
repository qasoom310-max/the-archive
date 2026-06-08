<?php

declare(strict_types=1);

namespace Modules\Pos\Livewire;

use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * POS Condiments browser — engine List view for `pos.condiment`. Rows link
 * to the condiment form. This is the global add-on list the register picker
 * draws from.
 */
#[Layout('components.layouts.app')]
#[Title('POS Condiments')]
final class PosCondiments extends Component
{
    public function render(): View
    {
        return view('pos::condiments', [
            'canCreate' => app(AccessControl::class)
                ->allows(Auth::user(), 'pos.condiment', Permission::Create),
        ]);
    }
}
