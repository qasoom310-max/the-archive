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

    /**
     * Page URL captured at mount, used by `navUrl()` to build prev/next
     * record links. Stored as a property (not re-read at render time)
     * because on subsequent Livewire AJAX requests `request()->url()`
     * returns the Livewire endpoint (`/livewire/update`) instead of the
     * original page URL — auto-save re-renders the form, the chevron
     * links get rebuilt, and "Next" then points at `/livewire/<id>` which
     * 404s. mount() is called once per page load (and again on each
     * wire:navigate to a sibling record) so this stays in lockstep with
     * the record being viewed.
     */
    public string $baseUrl = '';

    /** @var array<string, mixed> */
    public array $form = [];

    /** @var array<string, TemporaryUploadedFile> */
    public array $uploads = [];

    /**
     * Direct-upload destination paths set by FormImageUploadController:
     * `[<field> => 'pos_products/abc.webp']`. Populated by the Blade's
     * Alpine wrapper when the user picks a file — written to the record
     * in `save()` without going through Livewire's temp-file pipeline.
     *
     * @var array<string, string>
     */
    public array $imagePaths = [];

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
        $this->baseUrl = (string) request()->url();

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

            $value = $record->getAttribute($field->field);

            // Backed enums (e.g. PrepStation, OrderState cast via enum) must
            // be flattened to their scalar `value` before landing in $form.
            // Reasons: (1) the `<select>` widget compares against string
            // option values, (2) the `in:` validation rule string-casts the
            // value and a BackedEnum has no __toString — the validator dies
            // with "Object of class X could not be converted to string"
            // the moment the form auto-saves (e.g. AR pill click).
            if ($value instanceof \BackedEnum) {
                $value = $value->value;
            }

            $this->form[$field->field] = $value;
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

        // Flush the just-buffered locale value before the pill swap renders —
        // otherwise an edit made on the EN pill could sit unsaved while the
        // user works on AR. updated() won't fire for this server-side mutation
        // of $translations, so call autoSave directly.
        $this->autoSave();
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

    /**
     * Livewire lifecycle hook — fires after any public property is updated.
     * For text inputs `wire:model.live.debounce.500ms` debounces the request
     * client-side; checkbox/select fire instantly. We forward each change to
     * `autoSave()` once the row exists. The hook receives the dotted path
     * (e.g. `form.name`, `imagePaths.image_path`, `translations.name.ar`),
     * so we filter to the three buffers `save()` actually writes through.
     */
    public function updated(string $name): void
    {
        if ($this->recordId === null) {
            return;
        }

        if (
            ! str_starts_with($name, 'form.')
            && ! str_starts_with($name, 'translations.')
            && ! str_starts_with($name, 'imagePaths.')
        ) {
            return;
        }

        $this->autoSave();
    }

    /**
     * Silent variant of `save()` for Odoo-style auto-save. Same persistence
     * path (so the same validation / ACL / chatter / translatable / image
     * code runs) but: no flash toast, no redirect, ValidationException is
     * swallowed (inline field errors already render via `@error`). Does
     * nothing on a brand-new record — the row needs an id first, which
     * comes from the explicit Save button.
     */
    public function autoSave(): void
    {
        if ($this->recordId === null) {
            return;
        }

        try {
            $this->save(silent: true);
        } catch (\Illuminate\Validation\ValidationException) {
            // Errors already populated $this->errors → rendered under each
            // field. Auto-save just refuses to commit. The next keystroke
            // that fixes the invalid field will fire updated() and retry.
        }
    }

    public function save(bool $silent = false): void
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

        // Direct-upload paths populated by FormImageUploadController via
        // Alpine in the Blade. Empty string from the Alpine wrapper means
        // "no change" (vs an explicit clear, which isn't a feature yet).
        foreach ($this->imagePaths as $attribute => $path) {
            if ($path !== '') {
                $record->setAttribute($attribute, $path);
            }
        }

        // Legacy Livewire WithFileUploads path — kept for any field that
        // hasn't been migrated to the direct controller upload. Empty
        // array on the normal POS path so this loop is a no-op.
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

        // Auto-save fires on every keystroke — flashing "Saved." every
        // 500 ms and (worse) redirecting would be unusable. Skip both
        // when silent; the inline status pill in the Blade is the
        // visible confirmation for that path.
        if ($silent) {
            return;
        }

        // Visible confirmation: app layout reads this flash on the next render
        // (covers redirects, including a redirect back to the same URL where
        // nothing else visibly changes — e.g. POS product edit).
        session()->flash('toast', $isNew ? 'Created.' : 'Saved.');

        // Explicit redirect set by the host (e.g. legacy "back to list"
        // wiring) wins; otherwise, transition a freshly-created record
        // to its canonical edit URL by swapping the trailing `/new`
        // segment for the new id. This kills two birds:
        //   - Refreshing the page no longer resurfaces the empty "new"
        //     form, so the user can't accidentally create a duplicate.
        //   - Save button → status pill on the next render (auto-save
        //     mode), so a double-click on the original Save button
        //     can't fire save() a second time.
        if ($this->redirectTo !== '') {
            $this->redirect($this->redirectTo, navigate: true);

            return;
        }

        if ($isNew && $this->baseUrl !== '') {
            $editUrl = preg_replace('@/[^/]+$@', '/' . $record->getKey(), $this->baseUrl);
            if ($editUrl !== null) {
                $this->redirect($editUrl, navigate: true);
            }
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
                // 4 MB cap covers normal product photos without bloating
                // storage. SVG excluded — stored-XSS risk via inline <script>.
                // PHP's upload_max_filesize ultimately caps this — bumping
                // the rule alone won't help if php.ini is set lower.
                $rules['uploads.' . $field->field] = ['nullable', 'mimes:jpg,jpeg,png,gif,webp,bmp,avif,heic,heif', 'max:4096'];

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

    /**
     * Primary key of the record immediately before the current one in
     * ascending PK order — null on a new record (no current id) and
     * null when the current record is the first in the table. Hosts
     * the prev-arrow nav in the form header. Plain PK ordering keeps
     * the engine model-agnostic; could later honour the list arch's
     * default_sort, but PK is what users intuitively expect when
     * paging through "the records I just viewed in the list."
     */
    public function prevId(): ?int
    {
        return $this->neighborId(direction: 'prev');
    }

    public function nextId(): ?int
    {
        return $this->neighborId(direction: 'next');
    }

    /**
     * @param  'prev'|'next'  $direction
     */
    private function neighborId(string $direction): ?int
    {
        if ($this->recordId === null) {
            return null;
        }

        $model = $this->model;
        $pk = (new $model())->getKeyName();

        $query = $model::query();
        if ($direction === 'prev') {
            $query->where($pk, '<', $this->recordId)->orderByDesc($pk);
        } else {
            $query->where($pk, '>', $this->recordId)->orderBy($pk);
        }

        $value = $query->value($pk);

        return $value === null ? null : (int) $value;
    }

    /**
     * Build the URL of a sibling record by swapping the trailing
     * segment of the current request URL. Lets the engine work for
     * any host route shaped `/.../{id}` without the host needing to
     * declare a "record URL template" — the URL we're rendering AT
     * already encodes the right pattern.
     */
    public function navUrl(int $id): string
    {
        // Use the captured mount-time URL, NOT `request()->url()`. The
        // latter returns `/livewire/update` during an AJAX request (which
        // auto-save fires on every keystroke), and the regex below would
        // splice the id in there → `/livewire/<id>` → 404 on click.
        $base = $this->baseUrl !== '' ? $this->baseUrl : (string) request()->url();
        $replaced = preg_replace('@/[^/]+$@', '/' . $id, $base);

        // preg_replace returns null on regex error — guard against it
        // and fall back to the base URL so a navigation click is at
        // worst a no-op, never a 404.
        return $replaced ?? $base;
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
            'prevId' => $this->prevId(),
            'nextId' => $this->nextId(),
        ]);
    }
}
