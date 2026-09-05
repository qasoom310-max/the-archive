<?php

declare(strict_types=1);

namespace Modules\Limousine\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Limousine\Models\LimoCustomer;

#[Layout('components.layouts.app')]
#[Title('Customer')]
final class CustomerForm extends Component
{
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
        return view('limousine::customer-form', [
            'customer' => $this->id !== null ? LimoCustomer::query()->find($this->id) : null,
        ]);
    }
}
