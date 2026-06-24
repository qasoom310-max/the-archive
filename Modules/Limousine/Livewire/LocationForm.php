<?php

declare(strict_types=1);

namespace Modules\Limousine\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Limousine\Models\LimoLocation;

#[Layout('components.layouts.app')]
#[Title('Location')]
final class LocationForm extends Component
{
    public ?int $id = null;

    public function mount(?int $id = null): void
    {
        $this->id = $id;
    }

    public function render(): View
    {
        return view('limousine::location-form', [
            'location' => $this->id !== null ? LimoLocation::query()->find($this->id) : null,
        ]);
    }
}
