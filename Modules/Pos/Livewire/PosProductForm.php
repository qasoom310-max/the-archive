<?php

declare(strict_types=1);

namespace Modules\Pos\Livewire;

use App\Livewire\Concerns\EditsRecordViaFormView;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Pos\Models\PosProduct;

#[Layout('components.layouts.app')]
#[Title('POS Product')]
final class PosProductForm extends Component
{
    use EditsRecordViaFormView;

    protected function formModel(): string
    {
        return PosProduct::class;
    }

    protected function formView(): string
    {
        return 'pos::product-form';
    }

    protected function formVar(): string
    {
        return 'product';
    }
}
