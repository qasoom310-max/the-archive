<?php

declare(strict_types=1);

namespace Modules\Pos\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * POS Orders browser — renders the metadata-driven List/Kanban engine
 * views for the `pos.order` model (every order across all sessions).
 */
#[Layout('components.layouts.app')]
#[Title('POS Orders')]
final class PosOrders extends Component
{
    #[Url]
    public string $tab = 'list';

    public function render(): View
    {
        return view('pos::orders');
    }
}
