<?php

declare(strict_types=1);

namespace Modules\Accounting\Livewire;

use Illuminate\Contracts\View\View;
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
    public ?int $id = null;

    public function mount(?int $id = null): void
    {
        $this->id = $id;
    }

    public function render(): View
    {
        $entry = $this->id !== null ? JournalEntry::query()->find($this->id) : null;

        if ($entry !== null) {
            request()->attributes->set('breadcrumb_terminal_label', $entry->number);
        }

        return view('accounting::journal-entry-form', [
            'entry' => $entry,
        ]);
    }
}
