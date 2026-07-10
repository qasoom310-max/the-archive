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
use Modules\Pos\Models\PosIngredientCategory;

/**
 * Create/edit a POS ingredient category via the engine FormView (EN/AR name
 * pills, sequence, active). Thin wrapper — mirrors PosCondimentForm.
 */
#[Layout('components.layouts.app')]
#[Title('POS Ingredient Category')]
final class PosIngredientCategoryForm extends Component
{
    public ?int $id = null;

    public function mount(?int $id = null): void
    {
        $this->id = $id;
    }

    public function render(): View
    {
        $category = $this->id !== null ? PosIngredientCategory::query()->find($this->id) : null;

        return view('pos::ingredient-category-form', [
            'category' => $category,
            'canCreate' => app(AccessControl::class)
                ->allows(Auth::user(), 'pos.ingredient_category', Permission::Create),
        ]);
    }
}
