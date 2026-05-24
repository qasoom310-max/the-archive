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
 * **The "Language" row is special** — it's a per-user preference, not a
 * system setting. mount() pre-fills from `Auth::user()->language` (with
 * `company.language` as the system-wide fallback), and save() writes
 * back to the user row instead of the `ir_config_parameter` table. So
 * Faraj picking Arabic doesn't flip Qassim into Arabic. The system
 * `company.language` is still the seed default for new users / the
 * guest /login page.
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

    /**
     * The one key that's actually a per-user preference. mount() and
     * save() route it to `users.language` instead of the system
     * settings table.
     */
    private const PER_USER_LANGUAGE_KEY = 'company.language';

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
                    'value' => $this->initialValue($param->key),
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
        $this->selects[self::PER_USER_LANGUAGE_KEY] = [
            ['value' => 'en', 'label' => 'English'],
            ['value' => 'ar', 'label' => 'العربية'],
        ];
    }

    /**
     * Initial value for a row in the form. The language key is
     * special-cased: it reflects the logged-in user's preference, not
     * the system default. Anything else reads through SettingManager.
     */
    private function initialValue(string $key): mixed
    {
        if ($key === self::PER_USER_LANGUAGE_KEY) {
            return $this->effectiveLanguage();
        }

        return Setting::get($key);
    }

    /**
     * The locale the user is actually using right now: their personal
     * row preference, falling back to the system-wide default. Mirrors
     * the read order in `App\Http\Middleware\SetLocale`.
     */
    private function effectiveLanguage(): string
    {
        $user = Auth::user();
        if ($user instanceof User && $user->language !== null && $user->language !== '') {
            return $user->language;
        }

        return (string) Setting::get(self::PER_USER_LANGUAGE_KEY, 'en');
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

        // Snapshot the EFFECTIVE language before the write so we can
        // detect a flip (`en` → `ar` or vice versa) and trigger a
        // full-page reload — the master layout's `dir` attribute and
        // translated chrome are only rebuilt on a fresh request. We
        // compare effective (user OR system) rather than the raw user
        // column so flipping from "null/inherit en" to "ar" still
        // triggers a reload.
        $previousLanguage = $this->effectiveLanguage();

        $allowed = $this->allowedKeysOrNull();

        /** @var array<string, mixed> $systemValues */
        $systemValues = [];
        foreach ($this->form as $row) {
            // Re-filter here even though mount() already trimmed the
            // form: defence in depth against a crafted `$set` payload
            // that injects a forbidden key into the array.
            if ($allowed !== null && ! in_array($row['key'], $allowed, true)) {
                continue;
            }

            if ($row['key'] === self::PER_USER_LANGUAGE_KEY) {
                $this->persistUserLanguage($row['value']);

                continue;
            }

            $systemValues[$row['key']] = $row['value'];
        }

        if ($systemValues !== []) {
            app(SettingManager::class)->setMany($systemValues);
        }

        // Reflect the persisted+re-cast values back into the form (the
        // language row re-reads from the user, others from settings).
        foreach ($this->form as $i => $row) {
            $this->form[$i]['value'] = $this->initialValue($row['key']);
        }

        $this->saved = true;

        $newLanguage = $this->effectiveLanguage();

        if ($previousLanguage !== $newLanguage) {
            // Fire a browser-level event the layout's Alpine listener
            // catches. We can't just `redirect()` because Livewire would
            // swap the component without unloading the page; a reload
            // is the only way to re-evaluate every `__()` and the
            // `<html dir>` attribute.
            $this->dispatch('language-changed');
        }
    }

    /**
     * Write the language choice to the logged-in user's row. Empty /
     * unrecognised values blank the column → the user falls back to
     * the system default on the next request.
     */
    private function persistUserLanguage(mixed $value): void
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            return;
        }

        $code = is_string($value) ? strtolower(trim($value)) : '';
        // Anything other than the supported set blanks the column so
        // the SetLocale middleware falls back to the system default
        // instead of parking the user on an unsupported locale.
        $user->language = in_array($code, ['en', 'ar'], true) ? $code : null;
        $user->save();
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
