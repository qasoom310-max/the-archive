<?php

declare(strict_types=1);

namespace Modules\Pos\Livewire;

use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * POS Products browser — renders the metadata-driven List/Kanban engine
 * views for the `pos.product` model. Rows link to the product form.
 */
#[Layout('components.layouts.app')]
#[Title('POS Products')]
final class PosProducts extends Component
{
    #[Url]
    public string $tab = 'list';

    public function render(): View
    {
        return view('pos::products', [
            'canCreate' => app(AccessControl::class)
                ->allows(Auth::user(), 'pos.product', Permission::Create),
        ]);
    }
}
