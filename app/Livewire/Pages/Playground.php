<?php

declare(strict_types=1);

namespace App\Livewire\Pages;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('View Engine')]
final class Playground extends Component
{
    #[Url]
    public string $tab = 'list';

    public function render(): View
    {
        return view('livewire.pages.playground');
    }
}
