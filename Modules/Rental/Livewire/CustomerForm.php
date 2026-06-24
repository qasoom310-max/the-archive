<?php

declare(strict_types=1);

namespace Modules\Rental\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Rental\Models\RentalCustomer;

#[Layout('components.layouts.app')]
#[Title('Customer')]
final class CustomerForm extends Component
{
    public ?int $id = null;

    public function mount(?int $id = null): void
    {
        $this->id = $id;
    }

    public function render(): View
    {
        return view('rental::customer-form', [
            'customer' => $this->id !== null ? RentalCustomer::query()->find($this->id) : null,
        ]);
    }
}
