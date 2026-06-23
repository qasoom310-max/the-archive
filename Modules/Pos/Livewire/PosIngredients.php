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
 * POS Ingredients browser — engine List view for `pos.ingredient`. Rows link
 * to the ingredient form. The raw-material catalogue the recipe editor draws
 * from (never shown at the register).
 */
#[Layout('components.layouts.app')]
#[Title('POS Ingredients')]
final class PosIngredients extends Component
{
    public function render(): View
    {
        return view('pos::ingredients', [
            'canCreate' => app(AccessControl::class)
                ->allows(Auth::user(), 'pos.ingredient', Permission::Create),
        ]);
    }
}
