<?php

declare(strict_types=1);

namespace Modules\Pos\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Pos\Models\PosFloor;

/**
 * Create/edit a POS floor via the engine FormView. Thin wrapper.
 */
#[Layout('components.layouts.app')]
#[Title('POS Floor')]
final class PosFloorForm extends Component
{
    public ?int $id = null;

    public function mount(?int $id = null): void
    {
        $this->id = $id;
    }

    public function render(): View
    {
        return view('pos::floor-form', [
            'floor' => $this->id !== null ? PosFloor::query()->find($this->id) : null,
        ]);
    }
}
