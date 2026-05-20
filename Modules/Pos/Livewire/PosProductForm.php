<?php

declare(strict_types=1);

namespace Modules\Pos\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Pos\Models\PosProduct;

#[Layout('components.layouts.app')]
#[Title('POS Product')]
final class PosProductForm extends Component
{
    public ?int $id = null;

    public function mount(?int $id = null): void
    {
        $this->id = $id;
    }

    /**
     * After the engine FormView saves, redirect to the POS products list.
     *
     * Tradeoff: the recipe editor lives on the product detail page, so a
     * brand-new product's recipe is one extra click away from the list.
     * The shared layout shows a "Saved." toast on the destination, so
     * the user has explicit confirmation of the save.
     */
    #[On('record-saved')]
    public function onSaved(int $id): void
    {
        $this->redirect('/app/pos/product', navigate: true);
    }

    public function render(): View
    {
        return view('pos::product-form', [
            'product' => $this->id !== null ? PosProduct::query()->find($this->id) : null,
        ]);
    }
}
