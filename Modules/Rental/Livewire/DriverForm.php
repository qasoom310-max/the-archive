<?php

declare(strict_types=1);

namespace Modules\Rental\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Rental\Models\Driver;

#[Layout('components.layouts.app')]
#[Title('Driver')]
final class DriverForm extends Component
{
    public ?int $id = null;

    public function mount(?int $id = null): void
    {
        $this->id = $id;
    }

    public function render(): View
    {
        return view('rental::driver-form', [
            'driver' => $this->id !== null ? Driver::query()->find($this->id) : null,
        ]);
    }
}
