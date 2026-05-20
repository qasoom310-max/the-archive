<?php

declare(strict_types=1);

namespace Modules\Pos\Livewire;

use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * POS Categories browser — the metadata-driven engine List view for the
 * `pos.category` model. Rows link to the category form (hierarchy is
 * edited there via the dynamic parent picker).
 */
#[Layout('components.layouts.app')]
#[Title('POS Categories')]
final class PosCategories extends Component
{
    public function render(): View
    {
        return view('pos::categories', [
            'canCreate' => app(AccessControl::class)
                ->allows(Auth::user(), 'pos.category', Permission::Create),
        ]);
    }
}
