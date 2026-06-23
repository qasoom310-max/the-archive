<?php

declare(strict_types=1);

namespace Modules\Pos\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Pos\Models\PosIngredient;

/**
 * Create/edit a POS ingredient via the engine FormView (EN/AR name pills,
 * cost, stock, unit, sequence). Thin wrapper — mirrors PosCondimentForm.
 */
#[Layout('components.layouts.app')]
#[Title('POS Ingredient')]
final class PosIngredientForm extends Component
{
    public ?int $id = null;

    public function mount(?int $id = null): void
    {
        $this->id = $id;
    }

    public function render(): View
    {
        $ingredient = $this->id !== null ? PosIngredient::query()->find($this->id) : null;

        return view('pos::ingredient-form', [
            'ingredient' => $ingredient,
        ]);
    }
}
