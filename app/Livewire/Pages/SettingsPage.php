<?php

declare(strict_types=1);

namespace App\Livewire\Pages;

use App\Erp\Money\Currencies;
use App\Erp\Settings\Setting;
use App\Erp\Settings\SettingManager;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * System configuration hub. Renders every `ir_config_parameter` grouped
 * into tabs, with the control type driven by each parameter's `type`.
 * Admin-only (Odoo's Settings = Administration).
 *
 * `$form` is an index-keyed list (not keyed by the dotted setting key)
 * so Livewire's dot-path `wire:model` binding doesn't misread keys like
 * `company.name` as nested arrays.
 */
#[Layout('components.layouts.app')]
#[Title('Settings')]
final class SettingsPage extends Component
{
    /** @var list<array{key: string, label: string, type: string, group: string, description: string|null, value: mixed}> */
    public array $form = [];

    /**
     * Per-key dropdown options for settings that should render as a
     * `<select>` instead of free text. Keyed by setting key (e.g.
     * `currency.default`); each entry is a list of `[value, label]`
     * pairs the view iterates. Extensible — add another key here if a
     * future setting needs an enumerated picker.
     *
     * @var array<string, list<array{value: string, label: string}>>
     */
    public array $selects = [];

    public bool $saved = false;

    public function mount(): void
    {
        $this->authorizeAdmin();

        foreach (app(SettingManager::class)->grouped() as $params) {
            foreach ($params as $param) {
                $this->form[] = [
                    'key' => $param->key,
                    'label' => $param->label,
                    'type' => $param->type,
                    'group' => $param->group,
                    'description' => $param->description,
                    'value' => Setting::get($param->key),
                ];
            }
        }

        // Currency picker — the catalogue is the central registry, so
        // adding a new ISO code in `Currencies::all()` immediately
        // surfaces it here without changing the settings page.
        $this->selects['currency.default'] = array_map(
            fn ($c): array => ['value' => $c->code, 'label' => $c->label()],
            array_values(Currencies::all()),
        );

        // Language picker — the supported set is whitelisted in the
        // SetLocale middleware. Labels stay localised: an English user
        // sees "English / Arabic", an Arabic user sees "Arabic /
        // English" (the in-language native names side-by-side).
        $this->selects['company.language'] = [
            ['value' => 'en', 'label' => 'English'],
            ['value' => 'ar', 'label' => 'العربية'],
        ];
    }

    private function authorizeAdmin(): void
    {
        $user = Auth::user();

        abort_unless($user instanceof User && $user->isAdmin(), 403, 'Settings are administrator-only.');
    }

    public function save(): void
    {
        $this->authorizeAdmin();

        // Snapshot the language BEFORE the write so we can detect a flip
        // (`en` → `ar` or vice versa) and trigger a full-page reload —
        // the master layout's `dir` attribute and translated chrome are
        // only rebuilt on a fresh request.
        $previousLanguage = (string) Setting::get('company.language', 'en');

        /** @var array<string, mixed> $values */
        $values = [];
        foreach ($this->form as $row) {
            $values[$row['key']] = $row['value'];
        }

        app(SettingManager::class)->setMany($values);

        // Reflect the persisted+re-cast values back into the form.
        foreach ($this->form as $i => $row) {
            $this->form[$i]['value'] = Setting::get($row['key']);
        }

        $this->saved = true;

        $newLanguage = (string) Setting::get('company.language', 'en');

        if ($previousLanguage !== $newLanguage) {
            // Fire a browser-level event the layout's Alpine listener
            // catches. We can't just `redirect()` because Livewire would
            // swap the component without unloading the page; a reload
            // is the only way to re-evaluate every `__()` and the
            // `<html dir>` attribute.
            $this->dispatch('language-changed');
        }
    }

    public function updated(): void
    {
        $this->saved = false;
    }

    public function render(): View
    {
        /** @var array<string, list<int>> $tabs */
        $tabs = [];
        foreach ($this->form as $i => $row) {
            $tabs[$row['group']][] = $i;
        }

        return view('livewire.pages.settings', ['tabs' => $tabs]);
    }
}
