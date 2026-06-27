<?php

declare(strict_types=1);

namespace Modules\Rental\Livewire;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('Cars')]
final class Vehicles extends Component
{
    public function render(): View
    {
        $user = Auth::user();

        return view('rental::vehicles', [
            'canManage' => $user instanceof User && $user->canApproveMaintenance(),
        ]);
    }
}
