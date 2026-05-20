<?php

declare(strict_types=1);

namespace Modules\Pos\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Pos\Models\PosCategory;

/**
 * Create/edit a POS category via the engine FormView. The parent picker
 * is a dynamic, model-sourced select (no bespoke component).
 */
#[Layout('components.layouts.app')]
#[Title('POS Category')]
final class PosCategoryForm extends Component
{
    public ?int $id = null;

    public function mount(?int $id = null): void
    {
        $this->id = $id;
    }

    #[On('record-saved')]
    public function onSaved(): void
    {
        $this->redirect('/app/pos/category', navigate: true);
    }

    public function render(): View
    {
        return view('pos::category-form', [
            'category' => $this->id !== null ? PosCategory::query()->find($this->id) : null,
        ]);
    }
}
