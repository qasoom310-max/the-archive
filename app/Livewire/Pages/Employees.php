<?php

declare(strict_types=1);

namespace App\Livewire\Pages;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Staff directory — list of employees. Admin-only (HR data). Rows link to the
 * employee profile; the profile links on to that employee's payroll.
 */
#[Layout('components.layouts.app')]
#[Title('Employees')]
final class Employees extends Component
{
    public function mount(): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->isAdmin(), 403);
    }

    public function render(): View
    {
        return view('livewire.pages.employees', [
            'employees' => Employee::query()->orderBy('active', 'desc')->orderBy('sequence')->orderBy('name')->get(),
        ]);
    }
}
