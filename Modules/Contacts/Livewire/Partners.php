<?php

declare(strict_types=1);

namespace Modules\Contacts\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('Contacts')]
final class Partners extends Component
{
    #[Url]
    public string $tab = 'kanban';

    public function render(): View
    {
        return view('contacts::partners');
    }
}
