<?php

declare(strict_types=1);

namespace Modules\Pos\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Pos\Models\PosTable;

/**
 * Create/edit a POS table via the engine FormView. Thin wrapper.
 */
#[Layout('components.layouts.app')]
#[Title('POS Table')]
final class PosTableForm extends Component
{
    public ?int $id = null;

    public function mount(?int $id = null): void
    {
        $this->id = $id;
    }

    public function render(): View
    {
        return view('pos::table-form', [
            'table' => $this->id !== null ? PosTable::query()->find($this->id) : null,
        ]);
    }
}
