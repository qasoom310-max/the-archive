<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;

/**
 * Thin record-detail page that hands a record to a Blade view (which renders the
 * engine FormView). Holds the shared id/mount/render plumbing — including the
 * breadcrumb terminal-label swap so the topbar reads the record's name instead
 * of its raw id. Using components declare the model, the view, and the view's
 * variable name; override {@see recordLabel()} when the label isn't `name`.
 */
trait EditsRecordViaFormView
{
    #[Locked]
    public ?int $id = null;

    public function mount(?int $id = null): void
    {
        $this->id = $id;
    }

    public function render(): View
    {
        $model = $this->formModel();
        $record = $this->id !== null ? $model::query()->find($this->id) : null;

        if ($record !== null) {
            request()->attributes->set('breadcrumb_terminal_label', $this->recordLabel($record));
        }

        return view($this->formView(), [$this->formVar() => $record]);
    }

    /** @return class-string<Model> */
    abstract protected function formModel(): string;

    abstract protected function formView(): string;

    abstract protected function formVar(): string;

    /** The breadcrumb label for a loaded record — its name by default. */
    protected function recordLabel(Model $record): string
    {
        return (string) $record->getAttribute('name');
    }
}
