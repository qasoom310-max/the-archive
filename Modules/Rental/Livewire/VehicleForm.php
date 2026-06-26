<?php

declare(strict_types=1);

namespace Modules\Rental\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Rental\Models\Vehicle;

#[Layout('components.layouts.app')]
#[Title('Car')]
final class VehicleForm extends Component
{
    public ?int $id = null;

    public function mount(?int $id = null): void
    {
        $this->id = $id;
    }

    public function render(): View
    {
        return view('rental::vehicle-form', [
            'vehicle' => $this->id !== null ? Vehicle::query()->find($this->id) : null,
        ]);
    }
}
