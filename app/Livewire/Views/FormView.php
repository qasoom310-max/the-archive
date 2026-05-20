<?php

declare(strict_types=1);

namespace App\Livewire\Views;

use App\Erp\Chatter\Chatterable;
use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use App\Erp\Views\FormFieldDef;
use App\Erp\Views\ViewArch;
use App\Erp\Views\ViewResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Abstract, metadata-driven Form view: builds an editable form (create
 * or edit) from the resolved `ir_ui_view` form arch, with validation,
 * image upload and an automatic Chatter log entry on save.
 *
 * @property-read ViewArch $arch
 */
final class FormView extends Component
{
    use WithFileUploads;

    /** @var class-string<Model> */
    public string $model;

    public string $modelKey = '';

    public ?int $recordId = null;

    public string $title = '';

    public string $redirectTo = '';

    /** @var array<string, mixed> */
    public array $form = [];

    /** @var array<string, TemporaryUploadedFile> */
    public array $uploads = [];

    /**
     * @param  class-string<Model>  $model
     */
    public function mount(
        string $model,
        string $modelKey = '',
        ?int $recordId = null,
        string $title = '',
        string $redirectTo = '',
    ): void {
        $this->model = $model;
        $this->modelKey = $modelKey;
        $this->recordId = $recordId;
        $this->title = $title;
        $this->redirectTo = $redirectTo;

        $record = $this->resolveRecord();

        foreach ($this->arch->formFields as $field) {
            $this->form[$field->field] = $record->getAttribute($field->field);
        }
    }

    #[Computed]
    public function arch(): ViewArch
    {
        return app(ViewResolver::class)->arch($this->modelKey, 'form');
    }

    /**
     * Effective select options: a model-sourced list when the field
     * declares `optionsSource` (relation picker), else the static arch
     * options. `excludeSelf` drops the record being edited so a row
     * can't point at itself (generic cycle guard for hierarchies).
     *
     * @return list<array{value: string, label: string}>
     */
    private function effectiveOptions(FormFieldDef $field): array
    {
        $source = $field->optionsSource;

        if ($source === null) {
            return $field->options;
        }

        $rows = $source->model::query()
            ->orderBy($source->orderBy ?? $source->labelField)
            ->get();

        $options = [];

        foreach ($rows as $row) {
            $value = (string) $row->getAttribute($source->valueField);

            if ($source->excludeSelf && $this->recordId !== null && $value === (string) $this->recordId) {
                continue;
            }

            $options[] = [
                'value' => $value,
                'label' => (string) $row->getAttribute($source->labelField),
            ];
        }

        return $options;
    }

    private function resolveRecord(): Model
    {
        if ($this->recordId !== null) {
            return $this->model::query()->findOrFail($this->recordId);
        }

        return new $this->model();
    }

    private function access(): AccessControl
    {
        return app(AccessControl::class);
    }

    private function may(Permission $permission): bool
    {
        return $this->modelKey === ''
            || $this->access()->allows(Auth::user(), $this->modelKey, $permission);
    }

    public function save(): void
    {
        $this->validate($this->rules());

        $record = $this->resolveRecord();
        $isNew = ! $record->exists;

        $required = $isNew ? Permission::Create : Permission::Write;
        if (! $this->may($required)) {
            $this->access()->authorize(Auth::user(), $this->modelKey, $required);
        }

        foreach ($this->arch->formFields as $field) {
            if ($field->isImage()) {
                continue;
            }

            $value = $this->form[$field->field] ?? null;

            // An unticked checkbox is `false`, not `null`; a blank number
            // is `0`, not `null` — keep NOT NULL boolean/numeric columns
            // happy and intent unambiguous.
            if ($field->widget === 'checkbox') {
                $value = (bool) $value;
            } elseif ($field->widget === 'number') {
                $value = ($value === null || $value === '') ? 0 : (float) $value;
            } elseif ($field->widget === 'select') {
                // "—" (no selection) must clear a nullable FK, not write ''.
                $value = ($value === '' || $value === null) ? null : $value;
            }

            $record->setAttribute($field->field, $value);
        }

        foreach ($this->uploads as $attribute => $file) {
            $stored = $file->store($record->getTable(), 'public');

            if ($stored !== false) {
                $record->setAttribute($attribute, $stored);
            }
        }

        $record->save();

        if ($record instanceof Chatterable) {
            $record->logChange($isNew ? 'Record created.' : 'Record updated.');
        }

        $this->dispatch('record-saved', id: $record->getKey());

        // Visible confirmation: app layout reads this flash on the next render
        // (covers redirects, including a redirect back to the same URL where
        // nothing else visibly changes — e.g. POS product edit).
        session()->flash('toast', $isNew ? 'Created.' : 'Saved.');

        if ($this->redirectTo !== '') {
            $this->redirect($this->redirectTo, navigate: true);
        }
    }

    /**
     * @return array<string, list<string>>
     */
    private function rules(): array
    {
        $rules = [];

        foreach ($this->arch->formFields as $field) {
            if ($field->isImage()) {
                $rules['uploads.' . $field->field] = ['nullable', 'image', 'max:2048'];

                continue;
            }

            $key = 'form.' . $field->field;
            $set = [$field->required ? 'required' : 'nullable'];

            // A `select` may legitimately hold either a string or an int
            // (many2one FK ids, enum keys, etc.) — no PHP-type rule fits.
            // The `in:` whitelist below already restricts the value, and
            // Laravel's `in` uses loose comparison so "1" matches 1.
            $typeRule = match ($field->widget) {
                'email' => 'email',
                'number' => 'numeric',
                'date', 'datetime' => 'date',
                'checkbox' => 'boolean',
                'select' => null,
                default => 'string',
            };

            if ($typeRule !== null) {
                $set[] = $typeRule;
            }

            if ($field->widget === 'select') {
                $opts = $this->effectiveOptions($field);

                if ($opts !== []) {
                    $set[] = 'in:' . implode(',', array_map(
                        static fn (array $o): string => $o['value'],
                        $opts,
                    ));
                }
            }

            $rules[$key] = $set;
        }

        return $rules;
    }

    public function render(): View
    {
        if (! $this->may(Permission::Read)) {
            return view('livewire.views.forbidden');
        }

        $options = [];
        foreach ($this->arch->formFields as $field) {
            if ($field->widget === 'select') {
                $options[$field->field] = $this->effectiveOptions($field);
            }
        }

        return view('livewire.views.form-view', [
            'fields' => $this->arch->formFields,
            'cols' => $this->arch->formCols,
            'record' => $this->resolveRecord(),
            'options' => $options,
        ]);
    }
}
