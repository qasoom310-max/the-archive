<?php

declare(strict_types=1);

namespace App\Erp\Branding;

use App\Erp\Settings\Setting;

/**
 * The look of a DATABASE — its theme (light/dark/system) and accent colour.
 *
 * This is branding, not a personal preference: Wanaan is yellow + light, Kaleem
 * can be its own colour + dark, and every user of that database sees the same
 * thing. Both live in the per-workspace `ir_config_parameter` table, so each
 * database keeps its own value with no per-tenant branching — exactly like
 * `company.name` and `company.logo`.
 *
 * Only a SUPER admin may change them (see {@see \App\Livewire\Pages\SettingsPage}).
 *
 * Reads are null-safe: an unset setting (or an unseeded workspace, or a value
 * that is no longer a valid option) falls back to the default, so the layout can
 * never render a bogus theme class or accent attribute.
 */
final class Appearance
{
    /** @var list<string> */
    public const THEMES = ['light', 'dark', 'system'];

    /** @var list<string> */
    public const ACCENTS = ['yellow', 'amber', 'orange', 'red', 'pink', 'violet', 'sky', 'emerald'];

    public const DEFAULT_THEME = 'light';

    public const DEFAULT_ACCENT = 'yellow';

    public const THEME_KEY = 'company.theme';

    public const ACCENT_KEY = 'company.accent';

    /** The active database's theme: light | dark | system. */
    public static function theme(): string
    {
        return self::oneOf(Setting::get(self::THEME_KEY), self::THEMES, self::DEFAULT_THEME);
    }

    /** The active database's accent (brand) colour. */
    public static function accent(): string
    {
        return self::oneOf(Setting::get(self::ACCENT_KEY), self::ACCENTS, self::DEFAULT_ACCENT);
    }

    /**
     * @param  list<string>  $allowed
     */
    private static function oneOf(mixed $value, array $allowed, string $default): string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : $default;
    }
}
