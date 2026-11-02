<?php

declare(strict_types=1);

namespace Modules\Limousine\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Limousine\Models\LimoExtra;

#[Layout('components.layouts.app')]
#[Title('Trip extra')]
final class ExtraForm extends Component
{
    #[Locked]
    public ?int $id = null;

    public function mount(int|string|null $id = null): void
    {
        // A route segment is always a string, and a non-numeric one
        // ("new") means a new record rather than a bad request.
        $this->id = is_numeric($id) ? (int) $id : null;
    }

    public function render(): View
    {
        return view('limousine::extra-form', [
            'extra' => $this->id !== null ? LimoExtra::query()->find($this->id) : null,
        ]);
    }
}
