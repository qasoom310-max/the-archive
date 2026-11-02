<?php

declare(strict_types=1);

namespace Modules\Limousine\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/** The price list of extras sold with a trip. The engine list does the access check. */
#[Layout('components.layouts.app')]
#[Title('Trip extras')]
final class Extras extends Component
{
    public function render(): View
    {
        return view('limousine::extras');
    }
}
