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
 * POS Ingredient Categories browser — engine List view for
 * `pos.ingredient_category`. The managed grouping (Oils, Bottles, Caps…) the
 * ingredient form and the production pickers draw from. Mirrors PosCondiments.
 */
#[Layout('components.layouts.app')]
#[Title('POS Ingredient Categories')]
final class PosIngredientCategories extends Component
{
    public function render(): View
    {
        return view('pos::ingredient-categories', [
            'canCreate' => app(AccessControl::class)
                ->allows(Auth::user(), 'pos.ingredient_category', Permission::Create),
        ]);
    }
}
