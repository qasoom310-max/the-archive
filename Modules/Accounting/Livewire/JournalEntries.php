<?php

declare(strict_types=1);

namespace Modules\Accounting\Livewire;

use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Journal Entries browser — engine List view for `accounting.journal_entry`.
 */
#[Layout('components.layouts.app')]
#[Title('Journal Entries')]
final class JournalEntries extends Component
{
    public function render(): View
    {
        return view('accounting::journal-entries', [
            'canCreate' => app(AccessControl::class)
                ->allows(Auth::user(), 'accounting.journal_entry', Permission::Create),
        ]);
    }
}
