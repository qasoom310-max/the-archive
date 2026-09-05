<?php

declare(strict_types=1);

namespace Modules\Rental\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Rental\Models\Driver;
use Livewire\Attributes\Locked;

#[Layout('components.layouts.app')]
#[Title('Driver')]
final class DriverForm extends Component
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
        return view('rental::driver-form', [
            'driver' => $this->id !== null ? Driver::query()->find($this->id) : null,
        ]);
    }
}
