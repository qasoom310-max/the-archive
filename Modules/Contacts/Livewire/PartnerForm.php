<?php

declare(strict_types=1);

namespace Modules\Contacts\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Contacts\Models\Partner;

#[Layout('components.layouts.app')]
#[Title('Contact')]
final class PartnerForm extends Component
{
    public ?int $id = null;

    public function mount(?int $id = null): void
    {
        $this->id = $id;
    }

    public function render(): View
    {
        return view('contacts::partner-form', [
            'partner' => $this->id !== null ? Partner::query()->find($this->id) : null,
        ]);
    }
}
