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
 * POS Floors browser — engine List view for `pos.floor`. Admin-managed
 * (deny-default ACL, like the product catalogue).
 */
#[Layout('components.layouts.app')]
#[Title('POS Floors')]
final class PosFloors extends Component
{
    public function render(): View
    {
        return view('pos::floors', [
            'canCreate' => app(AccessControl::class)
                ->allows(Auth::user(), 'pos.floor', Permission::Create),
        ]);
    }
}
