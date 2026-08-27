<?php

declare(strict_types=1);

namespace Modules\Limousine\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Limousine\Models\LimoDriver;

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
        return view('limousine::driver-form', [
            'driver' => $this->id !== null ? LimoDriver::query()->find($this->id) : null,
        ]);
    }
}
