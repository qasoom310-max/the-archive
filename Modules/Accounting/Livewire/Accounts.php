<?php

declare(strict_types=1);

namespace Modules\Accounting\Livewire;

use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Chart of Accounts browser — engine List view for `accounting.account`.
 */
#[Layout('components.layouts.app')]
#[Title('Chart of Accounts')]
final class Accounts extends Component
{
    public function render(): View
    {
        return view('accounting::accounts', [
            'canCreate' => app(AccessControl::class)
                ->allows(Auth::user(), 'accounting.account', Permission::Create),
        ]);
    }
}
