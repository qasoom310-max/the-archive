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
use Modules\Pos\Models\PosTable;
use Livewire\Attributes\Locked;

/**
 * Create/edit a POS table via the engine FormView. Thin wrapper.
 */
#[Layout('components.layouts.app')]
#[Title('POS Table')]
final class PosTableForm extends Component
{
    #[Locked]
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
        return view('pos::table-form', [
            'table' => $this->id !== null ? PosTable::query()->find($this->id) : null,
            'canCreate' => app(AccessControl::class)
                ->allows(Auth::user(), 'pos.table', Permission::Create),
        ]);
    }
}
