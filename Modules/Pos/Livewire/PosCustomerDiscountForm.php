<?php

declare(strict_types=1);

namespace Modules\Pos\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Pos\Models\PosCustomerDiscount;

/**
 * Create/edit a per-phone customer discount via the engine FormView (phone,
 * discount %, label, active). Thin wrapper — mirrors PosCondimentForm. The
 * engine FormView enforces the admin-only ACL on every render + save.
 */
#[Layout('components.layouts.app')]
#[Title('Customer Discount')]
final class PosCustomerDiscountForm extends Component
{
    public ?int $id = null;

    public function mount(?int $id = null): void
    {
        $this->id = $id;
    }

    public function render(): View
    {
        $discount = $this->id !== null ? PosCustomerDiscount::query()->find($this->id) : null;

        return view('pos::customer-discount-form', [
            'discount' => $discount,
        ]);
    }
}
