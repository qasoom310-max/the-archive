<?php

declare(strict_types=1);

namespace Modules\Limousine\Livewire;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('Locations')]
final class Locations extends Component
{
    public function render(): View
    {
        $user = Auth::user();

        return view('limousine::locations', [
            'canManage' => $user instanceof User && $user->canApproveMaintenance(),
        ]);
    }
}
