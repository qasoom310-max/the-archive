<?php

declare(strict_types=1);

namespace Modules\Limousine\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Limousine\Models\LimoExpense;

#[Layout('components.layouts.app')]
#[Title('Expenses')]
final class Expenses extends Component
{
    public function render(): View
    {
        return view('limousine::expenses', [
            'total' => (float) LimoExpense::query()->sum('amount'),
        ]);
    }
}
