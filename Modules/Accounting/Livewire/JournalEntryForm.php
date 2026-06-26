<?php

declare(strict_types=1);

namespace Modules\Accounting\Livewire;

use App\Livewire\Concerns\EditsRecordViaFormView;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Accounting\Models\JournalEntry;

/**
 * Create / edit a journal entry via the engine FormView. The line-item
 * grid will be added as a child component in a follow-up — for now this
 * lets users view/edit the header (date, narration, state).
 */
#[Layout('components.layouts.app')]
#[Title('Journal Entry')]
final class JournalEntryForm extends Component
{
    use EditsRecordViaFormView;

    protected function formModel(): string
    {
        return JournalEntry::class;
    }

    protected function formView(): string
    {
        return 'accounting::journal-entry-form';
    }

    protected function formVar(): string
    {
        return 'entry';
    }

    protected function recordLabel(Model $record): string
    {
        return (string) $record->getAttribute('number');
    }
}
