<?php

declare(strict_types=1);

namespace Modules\Pos\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
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

    public function render(): View
    {
        $product = $this->id !== null ? PosProduct::query()->find($this->id) : null;

        // Replace the terminal breadcrumb segment (raw record id) with
        // the product's display name so the topbar reads "Pos / Product
        // / كابتشينو" instead of "Pos / Product / 96". The layout reads
        // this attribute generically — set it on any record-detail page.
        if ($product !== null) {
            request()->attributes->set('breadcrumb_terminal_label', (string) $product->name);
        }

        return view('pos::product-form', [
            'product' => $product,
        ]);
    }
}
