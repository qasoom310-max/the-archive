<?php

declare(strict_types=1);

namespace Modules\Pos\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Pos\Models\PosCondiment;

/**
 * Create/edit a POS condiment via the engine FormView (EN/AR name pills,
 * price, active, sequence). Thin wrapper — mirrors PosCategoryForm.
 */
#[Layout('components.layouts.app')]
#[Title('POS Condiment')]
final class PosCondimentForm extends Component
{
    public ?int $id = null;

    public function mount(?int $id = null): void
    {
        $this->id = $id;
    }

    public function render(): View
    {
        $condiment = $this->id !== null ? PosCondiment::query()->find($this->id) : null;

        return view('pos::condiment-form', [
            'condiment' => $condiment,
        ]);
    }
}
