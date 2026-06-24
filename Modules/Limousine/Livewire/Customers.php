<?php

declare(strict_types=1);

namespace Modules\Limousine\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('Customers')]
final class Customers extends Component
{
    public function render(): View
    {
        return view('limousine::customers');
    }
}
