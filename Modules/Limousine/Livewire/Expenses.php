<?php

declare(strict_types=1);

namespace Modules\Limousine\Livewire;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
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
        $user = Auth::user();

        return view('limousine::expenses', [
            'total' => (float) LimoExpense::query()->sum('amount'),
            'canManage' => $user instanceof User && $user->canApproveMaintenance(),
        ]);
    }
}
