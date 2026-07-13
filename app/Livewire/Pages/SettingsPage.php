<?php

declare(strict_types=1);

namespace App\Livewire\Pages;

use App\Erp\Branding\Logo;
use App\Erp\Business\BusinessType;
use App\Erp\Money\Currencies;
use App\Erp\Settings\Setting;
use App\Erp\Settings\SettingManager;
use App\Models\ReportRecipient;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Modules\Pos\Services\DailyReport;

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
    use \App\Livewire\Concerns\HasAdminCheck;

    /**
     * Setting keys a non-admin user is allowed to view and modify.
     * Cashiers / sales users land here from the sidebar and should
     * only be able to flip language — everything else stays admin.
     *
     * @var list<string>
     */
    public const NON_ADMIN_KEYS = ['company.language'];

    /**
     * Setting keys only the SUPER admin may view/edit. Regular admins see
     * everything else but never these (the company "business type" is an
     * owner-level decision). Enforced in both mount() and save() via
     * {@see self::canSee()}.
     *
     * @var list<string>
     */
    public const SUPER_ADMIN_KEYS = ['company.business_type'];

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

    /**
     * Direct-upload paths populated by the Alpine wrapper around each
     * `image`-type setting row. Indexed by ROW POSITION (matching
     * `$form`'s index) so a dotted setting key like `company.logo` can
     * survive Livewire's dot-path traversal in `->set()` — same trick
     * `$form` itself uses. Written into the corresponding
     * `ir_config_parameter` row in `save()`. Empty entry = no new
     * upload this submit, keep existing path.
     *
     * @var array<int, string>
     */
    public array $imagePaths = [];

    public bool $saved = false;

    /**
     * Per-user appearance preference: light | dark | system. Personal (like
     * language), so every user — not just admins — may change it. Applied live
     * (no reload) by dispatching `theme-changed` to the layout's Alpine hook.
     */
    public string $theme = 'system';

    /**
     * Per-user accent (brand) colour. Personal (like theme). Applied live (no
     * reload) by dispatching `accent-changed` to the layout's Alpine hook,
     * which swaps `data-accent` on <html> → the CSS remaps the primary palette.
     *
     * @var list<string>
     */
    public const ACCENTS = ['yellow', 'amber', 'orange', 'red', 'pink', 'violet', 'sky', 'emerald'];

    public string $accent = 'yellow';

    /**
     * New recipient email being added in the admin-only "Daily Report" tab.
     * The daily sales + stock PDF is mailed to every {@see ReportRecipient}.
     */
    #[Validate('required|email|max:200')]
    public string $newRecipientEmail = '';

    public function mount(): void
    {
        // Auth still required — guests get bounced to /login by route
        // middleware before we ever reach here.
        abort_unless(Auth::check(), 403);

        $user = Auth::user();
        $this->theme = $user instanceof User && in_array($user->theme, ['light', 'dark', 'system'], true)
            ? $user->theme
            : 'system';
        $this->accent = $user instanceof User && in_array($user->accent, self::ACCENTS, true)
            ? $user->accent
            : 'yellow';

        foreach (app(SettingManager::class)->grouped() as $params) {
            foreach ($params as $param) {
                if (! $this->canSee($param->key)) {
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

        // Business-type picker — drives which apps/menus/features this database
        // exposes (a row in the General tab). SUPER-ADMIN ONLY: reshaping the
        // whole app surface is an owner-level decision, so it's gated by
        // SUPER_ADMIN_KEYS (canSee hides the row from regular admins).
        // Uses the BusinessType enum so values stay valid for Features.
        if ($this->isSuperAdmin()) {
            $this->selects['company.business_type'] = array_map(
                fn (BusinessType $t): array => ['value' => $t->value, 'label' => __($t->label())],
                BusinessType::all(),
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

        // Timezone picker — admin-only. Built once per mount; ~38
        // entries instead of the 400+ raw IANA list (one per unique
        // UTC offset).
        if ($this->isAdmin()) {
            $this->selects['company.timezone'] = $this->timezoneOptions();
        }

        // Logo style — how the brand logo is framed in the topbar for THIS
        // database (each workspace picks its own). Admin-only, like the logo
        // upload it sits beside.
        if ($this->isAdmin()) {
            $this->selects['company.logo_shape'] = [
                ['value' => Logo::SHAPE_NORMAL, 'label' => __('Normal — keep the logo’s own shape')],
                ['value' => Logo::SHAPE_CIRCLE, 'label' => __('Circle — crop to a round badge')],
            ];
        }
    }

    /**
     * Deduplicated timezone picker options. Iterates every IANA
     * identifier, groups by the zone's CURRENT UTC offset, picks one
     * representative per group, and labels the row with the offset
     * plus a handful of sample city names. Saudi Arabia / Bahrain /
     * Qatar / Kuwait / Iraq all sit on UTC+03:00 — they collapse to a
     * single row whose value is e.g. `Asia/Bahrain` but whose label
     * advertises every nearby country, so the combobox search hits a
     * country name even when the underlying IANA id is a different
     * city.
     *
     * "UTC" is preferred as the offset-0 representative because the
     * literal string is already saved as the seeded default and users
     * recognise it; otherwise the first non-Etc/ alphabetical IANA
     * identifier in each bucket wins.
     *
     * @return list<array{value: string, label: string}>
     */
    private function timezoneOptions(): array
    {
        $now = new \DateTimeImmutable();

        /** @var array<int, list<string>> $byOffset */
        $byOffset = [];
        foreach (\DateTimeZone::listIdentifiers() as $id) {
            $offset = (new \DateTimeZone($id))->getOffset($now);
            $byOffset[$offset] ??= [];
            $byOffset[$offset][] = $id;
        }

        ksort($byOffset);

        $options = [];
        foreach ($byOffset as $offset => $ids) {
            // Representative: UTC for offset 0 (special-cased so the
            // seeded default keeps working), otherwise the first non-
            // Etc/ alphabetical zone — DateTimeZone::listIdentifiers
            // already returns the list alphabetically.
            $rep = $offset === 0 && in_array('UTC', $ids, true)
                ? 'UTC'
                : (array_values(array_filter($ids, static fn (string $i): bool => ! str_starts_with($i, 'Etc/')))[0] ?? $ids[0]);

            // ALL cities in this offset bucket (skipping Etc/* which
            // are technical aliases) — long labels, but the combobox
            // is searchable so a user typing "Riyadh" or "Bahrain"
            // hits the UTC+03:00 row even when the rep is e.g.
            // "Africa/Addis_Ababa". Truncating the list (as we did
            // at first) defeats the search by hiding everyone whose
            // city wasn't in the alphabetical top N.
            $cities = array_values(array_filter(
                $ids,
                static fn (string $i): bool => ! str_starts_with($i, 'Etc/'),
            ));

            // Strip the region prefix from the city list for a cleaner
            // label — "Asia/Bahrain" → "Bahrain". Underscores in
            // multi-word cities become spaces ("New_York" → "New York").
            $cityLabel = implode(', ', array_map(
                static function (string $id): string {
                    $tail = str_contains($id, '/') ? substr((string) strrchr($id, '/'), 1) : $id;

                    return str_replace('_', ' ', $tail);
                },
                $cities,
            ));

            $hours = intdiv(abs($offset), 3600);
            $minutes = intdiv(abs($offset) % 3600, 60);
            $sign = $offset >= 0 ? '+' : '-';
            $offsetLabel = sprintf('UTC%s%02d:%02d', $sign, $hours, $minutes);

            $options[] = [
                'value' => $rep,
                'label' => "({$offsetLabel}) {$cityLabel}",
            ];
        }

        return $options;
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
     * Whether the current user may view/edit a given setting key.
     * Super admin: everything. Regular admin: everything EXCEPT the
     * super-admin-only keys. Non-admin: only the explicit allow-list.
     */
    private function canSee(string $key): bool
    {
        // Internal feature-override store — managed per-app (the app's own
        // Settings tab), never shown as a row in the central settings page.
        if (str_starts_with($key, 'features.')) {
            return false;
        }

        if ($this->isSuperAdmin()) {
            return true;
        }

        if (in_array($key, self::SUPER_ADMIN_KEYS, true)) {
            return false;
        }

        if ($this->isAdmin()) {
            return true;
        }

        return in_array($key, self::NON_ADMIN_KEYS, true);
    }


    private function isSuperAdmin(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->isSuperAdmin();
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

        /** @var array<string, mixed> $systemValues */
        $systemValues = [];
        foreach ($this->form as $i => $row) {
            // Re-filter here even though mount() already trimmed the
            // form: defence in depth against a crafted `$set` payload
            // that injects a forbidden key into the array.
            if (! $this->canSee($row['key'])) {
                continue;
            }

            if ($row['key'] === self::PER_USER_LANGUAGE_KEY) {
                $this->persistUserLanguage($row['value']);

                continue;
            }

            // Image-type setting: the upload widget writes the new path
            // into $imagePaths[<row index>] via FormImageUploadController.
            // Index keying (not setting-key keying) dodges Livewire's
            // dot-path interpretation of `$set('imagePaths.company.logo', …)`.
            // An empty entry means "no new upload this submit" → keep
            // the existing value rather than blanking it.
            if ($row['type'] === 'image') {
                $newPath = $this->imagePaths[$i] ?? null;
                if (is_string($newPath) && $newPath !== '') {
                    $systemValues[$row['key']] = $newPath;
                }

                continue;
            }

            $systemValues[$row['key']] = $row['value'];
        }

        if ($systemValues !== []) {
            app(SettingManager::class)->setMany($systemValues);
            app(\App\Erp\Activity\ActivityLogger::class)->log(
                'settings_updated',
                null,
                implode(', ', array_keys($systemValues)),
            );
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
        $desired = in_array($code, ['en', 'ar'], true) ? $code : null;

        // Skip the write when the column already matches — every Save
        // would otherwise touch `users` even when only Timezone or
        // Default Currency changed. Cheap correctness fix that also
        // avoids `updated_at` thrashing the activity log.
        if ($user->language === $desired) {
            return;
        }

        $user->language = $desired;
        $user->save();
    }

    /**
     * Set the logged-in user's appearance preference and apply it live. Fires
     * immediately (its own button group), independent of the top Save — like
     * the Daily Report actions. Available to every user (a personal choice).
     */
    public function setTheme(string $theme): void
    {
        abort_unless(Auth::check(), 403);

        if (! in_array($theme, ['light', 'dark', 'system'], true)) {
            return;
        }

        $user = Auth::user();
        if ($user instanceof User && $user->theme !== $theme) {
            $user->theme = $theme;
            $user->save();
        }

        $this->theme = $theme;

        // The layout's Alpine hook toggles the `.dark` class from this — no
        // full-page reload needed (unlike a language flip).
        $this->dispatch('theme-changed', value: $theme);
    }

    /**
     * Set the logged-in user's accent (brand) colour and apply it live.
     * Personal (available to every user), fires immediately.
     */
    public function setAccent(string $accent): void
    {
        abort_unless(Auth::check(), 403);

        if (! in_array($accent, self::ACCENTS, true)) {
            return;
        }

        $user = Auth::user();
        if ($user instanceof User && $user->accent !== $accent) {
            $user->accent = $accent;
            $user->save();
        }

        $this->accent = $accent;

        // The layout swaps `data-accent` on <html> from this → the CSS remaps
        // the whole primary palette instantly, no reload.
        $this->dispatch('accent-changed', value: $accent);
    }

    public function updated(): void
    {
        $this->saved = false;
    }

    /**
     * Whether the POS data the daily report reads from exists yet — the
     * "Daily Report" tab is only meaningful once POS is installed.
     */
    private function posReady(): bool
    {
        return Schema::hasTable('pos_orders') && Schema::hasTable('pos_products');
    }

    private function guardAdmin(): void
    {
        abort_unless($this->isAdmin(), 403);
    }

    public function addRecipient(): void
    {
        $this->guardAdmin();
        $this->validate();

        ReportRecipient::query()->firstOrCreate(
            ['email' => strtolower(trim($this->newRecipientEmail))],
            ['active' => true],
        );

        $this->newRecipientEmail = '';
    }

    public function removeRecipient(int $id): void
    {
        $this->guardAdmin();
        ReportRecipient::query()->whereKey($id)->delete();
    }

    /**
     * Send the report for the night that just closed to the recipient list
     * now — lets an admin verify delivery without waiting for 6 AM.
     */
    public function sendNow(): void
    {
        $this->guardAdmin();

        if (! $this->posReady()) {
            return;
        }

        $count = app(DailyReport::class)->sendLastClosedReport();

        session()->flash(
            'report_sent',
            $count > 0
                ? __('Report sent to :count recipient(s).', ['count' => $count])
                : __('Add at least one recipient first.'),
        );
    }

    public function render(): View
    {
        /** @var array<string, list<int>> $tabs */
        $tabs = [];
        foreach ($this->form as $i => $row) {
            $tabs[$row['group']][] = $i;
        }

        // Admin-only "Daily Report" tab — recipient list for the automated
        // 6:10 AM sales + stock PDF. Hidden for non-admins and until POS
        // (the data source) is installed.
        $reportTab = $this->isAdmin() && $this->posReady();

        // Admin-only "Users" tab — create staff accounts + grant view-only
        // app/database access. The tab embeds the UserManager component.
        $userTab = $this->isAdmin();

        return view('livewire.pages.settings', [
            'tabs' => $tabs,
            'reportTab' => $reportTab,
            'userTab' => $userTab,
            'recipients' => $reportTab
                ? ReportRecipient::query()->orderBy('email')->get()
                : collect(),
        ]);
    }
}
