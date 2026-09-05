<?php

declare(strict_types=1);

namespace Modules\Pos\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Pos\Models\PosCustomerDiscount;
use Livewire\Attributes\Locked;

/**
 * Create/edit a per-phone customer discount via the engine FormView (phone,
 * discount %, label, active). Thin wrapper — mirrors PosCondimentForm. The
 * engine FormView enforces the admin-only ACL on every render + save.
 */
#[Layout('components.layouts.app')]
#[Title('Customer Discount')]
final class PosCustomerDiscountForm extends Component
{
    #[Locked]
    public ?int $id = null;

    public function mount(int|string|null $id = null): void
    {
        // A route segment is always a string, and a non-numeric one
        // ("new") means a new record rather than a bad request.
        $id = is_numeric($id) ? (int) $id : null;

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
