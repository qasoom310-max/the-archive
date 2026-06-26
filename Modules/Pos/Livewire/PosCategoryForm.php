<?php

declare(strict_types=1);

namespace Modules\Pos\Livewire;

use App\Livewire\Concerns\EditsRecordViaFormView;
use Livewire\Attributes\Layout;
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
    use EditsRecordViaFormView;

    protected function formModel(): string
    {
        return PosCategory::class;
    }

    protected function formView(): string
    {
        return 'pos::category-form';
    }

    protected function formVar(): string
    {
        return 'category';
    }
}
