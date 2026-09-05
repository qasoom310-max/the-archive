<?php

declare(strict_types=1);

namespace Modules\Pos\Livewire;

use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Pos\Models\PosCondiment;

/**
 * Create/edit a POS condiment via the engine FormView (EN/AR name pills,
 * price, active, sequence). Thin wrapper — mirrors PosCategoryForm.
 */
#[Layout('components.layouts.app')]
#[Title('POS Condiment')]
final class PosCondimentForm extends Component
{
    public ?int $id = null;

    public function mount(int|string|null $id = null): void
    {
        // A route segment is always a string, and a non-numeric one
        // ("new") means a new record rather than a bad request.
        $id = is_numeric($id) ? (int) $id : null;

        $this->id = $id;
    }

    public function render(): View
    {
        $condiment = $this->id !== null ? PosCondiment::query()->find($this->id) : null;

        return view('pos::condiment-form', [
            'condiment' => $condiment,
            'canCreate' => app(AccessControl::class)
                ->allows(Auth::user(), 'pos.condiment', Permission::Create),
        ]);
    }
}
