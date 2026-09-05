<?php

declare(strict_types=1);

namespace Modules\Pos\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Pos\Models\PosFloor;
use Livewire\Attributes\Locked;

/**
 * Create/edit a POS floor via the engine FormView. Thin wrapper.
 */
#[Layout('components.layouts.app')]
#[Title('POS Floor')]
final class PosFloorForm extends Component
{
    #[Locked]
    public ?int $id = null;

    public function mount(int|string|null $id = null): void
    {
        // A route segment is always a string, and a non-numeric one
        // ("new") means a new record rather than a bad request.
        $id = is_numeric($id) ? (int) $id : null;

        $this->id = $id;
    }

    public function render(): View
    {
        return view('pos::floor-form', [
            'floor' => $this->id !== null ? PosFloor::query()->find($this->id) : null,
        ]);
    }
}
