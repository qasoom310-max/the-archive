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
 * Customer-discount browser — engine List view for `pos.customer_discount`.
 * Admin-only by deny-default ACL: cashiers have no grant on this model so
 * the engine Read guard 403s them; the superuser bypasses. Rows link to the
 * discount form. This is the per-phone open-discount table the register
 * applies when a matching customer is added.
 */
#[Layout('components.layouts.app')]
#[Title('Customer Discounts')]
final class PosCustomerDiscounts extends Component
{
    public function render(): View
    {
        return view('pos::customer-discounts', [
            'canCreate' => app(AccessControl::class)
                ->allows(Auth::user(), 'pos.customer_discount', Permission::Create),
        ]);
    }
}
