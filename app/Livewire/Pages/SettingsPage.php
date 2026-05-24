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
 *
 * Role-gated: admins see all keys; non-admins (e.g. POS cashiers) see
 * only the keys listed in {@see self::NON_ADMIN_KEYS}. The filter is
 * applied both at mount (so the form only contains allowed keys) and
 * inside save() (so a crafted Livewire payload can't escalate). The
 * view is fully data-driven, so widening non-admin access is a one-line
 * change in NON_ADMIN_KEYS.
 *
 * `$form` is an index-keyed list (not keyed by the dotted setting key)
 * so Livewire's dot-path `wire:model` binding doesn't misread keys like
 * `company.name` as nested arrays.
 */
#[Layout('components.layouts.app')]
#[Title('Settings')]
final class SettingsPage extends Component
{
    /**
     * Setting keys a non-admin user is allowed to view and modify.
     * Cashiers / sales users land here from the sidebar and should
     * only be able to flip language — everything else stays admin.
     *
     * @var list<string>
     */
    public const NON_ADMIN_KEYS = ['company.language'];

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
        // Auth still required — guests get bounced to /login by route
        // middleware before we ever reach here.
        abort_unless(Auth::check(), 403);

        $allowed = $this->allowedKeysOrNull();

        foreach (app(SettingManager::class)->grouped() as $params) {
            foreach ($params as $param) {
                if ($allowed !== null && ! in_array($param->key, $allowed, true)) {
                    continue;
                }

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
        // surfaces it here without changing the settings page. Only
        // built for admins; non-admins never see the currency row, so
        // skipping the catalogue avoids a pointless allocation.
        if ($this->isAdmin()) {
            $this->selects['currency.default'] = array_map(
                fn ($c): array => ['value' => $c->code, 'label' => $c->label()],
                array_values(Currencies::all()),
            );
        }

        // Language picker — the supported set is whitelisted in the
        // SetLocale middleware. Labels stay localised: an English user
        // sees "English / Arabic", an Arabic user sees "Arabic /
        // English" (the in-language native names side-by-side).
        $this->selects['company.language'] = [
            ['value' => 'en', 'label' => 'English'],
            ['value' => 'ar', 'label' => 'العربية'],
        ];
    }

    /**
     * @return list<string>|null returns null when the user is admin
     *                            (meaning "no filter"), otherwise the
     *                            whitelist a non-admin may view/edit.
     */
    private function allowedKeysOrNull(): ?array
    {
        return $this->isAdmin() ? null : self::NON_ADMIN_KEYS;
    }

    private function isAdmin(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->isAdmin();
    }

    public function save(): void
    {
        abort_unless(Auth::check(), 403);

        // Snapshot the language BEFORE the write so we can detect a flip
        // (`en` → `ar` or vice versa) and trigger a full-page reload —
        // the master layout's `dir` attribute and translated chrome are
        // only rebuilt on a fresh request.
        $previousLanguage = (string) Setting::get('company.language', 'en');

        $allowed = $this->allowedKeysOrNull();

        /** @var array<string, mixed> $values */
        $values = [];
        foreach ($this->form as $row) {
            // Re-filter here even though mount() already trimmed the
            // form: defence in depth against a crafted `$set` payload
            // that injects a forbidden key into the array.
            if ($allowed !== null && ! in_array($row['key'], $allowed, true)) {
                continue;
            }

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
