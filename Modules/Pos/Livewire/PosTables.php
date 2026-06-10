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
 * POS Tables browser — engine List view for `pos.table`. Admin-managed
 * (deny-default ACL, like the product catalogue).
 */
#[Layout('components.layouts.app')]
#[Title('POS Tables')]
final class PosTables extends Component
{
    public function render(): View
    {
        return view('pos::tables', [
            'canCreate' => app(AccessControl::class)
                ->allows(Auth::user(), 'pos.table', Permission::Create),
        ]);
    }
}
