<?php

declare(strict_types=1);

namespace App\Livewire\Views;

use App\Erp\Chatter\Chatterable;
use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use App\Erp\Translation\TranslatableModel;
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
     * Per-field translation buffer for fields marked `translatable: true`.
     * Shape: `[<field> => [<locale> => <string>]]`. Holds the full set of
     * translations across pill switches so values in an unfocused locale
     * survive a click. Hydrated in `mount()` from `getTranslations()`,
     * flushed back to the record in `save()` via `setTranslations()`.
     *
     * @var array<string, array<string, string>>
     */
    public array $translations = [];

    /**
     * Which locale each translatable field is currently editing — drives
     * the active pill in the UI and decides which value gets read/written
     * to `$form[$field]` on a pill switch.
     *
     * Defaults to the active app locale (driven by `company.language` via
     * `SetLocale` middleware). Falling back to 'en' when the active locale
     * isn't in `LOCALES` keeps the form sane if a third locale is added
     * mid-session without restarting.
     *
     * @var array<string, string>
     */
    public array $translationLocale = [];

    /**
     * Locales the pills offer. Aligned with Phase 12 (en + ar) — adding a
     * new locale means adding it here AND in `lang/<code>.json`. The form
     * silently ignores requests to switch to a locale not in this list.
     */
    private const LOCALES = ['en', 'ar'];

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
        $activeLocale = $this->activeLocale();

        foreach ($this->arch->formFields as $field) {
            // Translatable fields take a side-channel: we keep ALL locale
            // values in `$translations[<field>]`, and surface only the
            // active locale to `$form[<field>]`. Pill clicks swap the
            // surfaced value without losing the others.
            if ($field->isTranslatable() && $record instanceof TranslatableModel) {
                $all = $record->getTranslations($field->field);
                $this->translations[$field->field] = $all;
                $this->translationLocale[$field->field] = $activeLocale;
                $this->form[$field->field] = $all[$activeLocale] ?? '';

                continue;
            }

            $this->form[$field->field] = $record->getAttribute($field->field);
        }
    }

    /**
     * Pick the locale a translatable field should default to on mount.
     * Prefer the current app locale (so an Arabic-speaking admin lands on
     * the AR pill) and fall back to English if the app is in a locale the
     * pills don't currently support.
     */
    private function activeLocale(): string
    {
        $locale = app()->getLocale();

        return in_array($locale, self::LOCALES, true) ? $locale : 'en';
    }

    /**
     * Pill-click action. Persists the in-progress edit for the OLD locale
     * into the translation buffer, then swaps the surfaced value to the
     * new locale (loading it from the buffer, blank if never set).
     */
    public function switchLocale(string $fieldName, string $locale): void
    {
        if (! in_array($locale, self::LOCALES, true)) {
            return;
        }

        $current = $this->translationLocale[$fieldName] ?? $this->activeLocale();
        $value = $this->form[$fieldName] ?? '';

        $this->translations[$fieldName][$current] = is_string($value) ? $value : '';
        $this->translationLocale[$fieldName] = $locale;
        $this->form[$fieldName] = $this->translations[$fieldName][$locale] ?? '';
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
        // Drop any "poisoned" uploads — a TemporaryUploadedFile whose backing
        // file is gone from livewire-tmp (stale snapshot, cleanup race, the
        // 24-hour cleanupOldUploads sweep, etc.). Without this, validate()'s
        // `max:` rule blows up inside Flysystem with UnableToRetrieveMetadata
        // → 500, and the user has no way to recover except a hard refresh.
        // Surface it as a regular validation error instead.
        $expired = [];
        foreach ($this->uploads as $attribute => $file) {
            if (! $file instanceof TemporaryUploadedFile || ! $file->exists()) {
                unset($this->uploads[$attribute]);
                $expired['uploads.' . $attribute] = __('The image upload expired. Please re-select the file and try again.');
            }
        }
        if ($expired !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages($expired);
        }

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

            // Translatable field — flush the in-progress edit for the
            // currently-focused locale into the buffer, then write ALL
            // locales at once. Skips the cast branch below (translatable
            // is text-shaped by `isTranslatable()`'s widget guard).
            if ($field->isTranslatable() && $record instanceof TranslatableModel) {
                $activeLocale = $this->translationLocale[$field->field] ?? $this->activeLocale();
                $this->translations[$field->field][$activeLocale] = is_string($value) ? $value : '';

                $record->setTranslations($field->field, $this->translations[$field->field]);

                continue;
            }

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
                // Explicit mimes list instead of Laravel's `image` rule —
                // `image` excludes AVIF (and any future format Laravel hasn't
                // baked in yet). Listing extensions keeps modern phone-camera
                // uploads (HEIC/AVIF) working.
                // 8 MB so modern phone-camera images (3-5 MB webp/heic) fit
                // without users having to resize first. PHP's upload_max_filesize
                // ultimately caps this — bumping the rule alone won't help if
                // php.ini is set lower.
                $rules['uploads.' . $field->field] = ['nullable', 'mimes:jpg,jpeg,png,gif,webp,bmp,svg,avif,heic,heif', 'max:8192'];

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
            'locales' => self::LOCALES,
        ]);
    }
}
