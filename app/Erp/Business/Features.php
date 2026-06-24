<?php

declare(strict_types=1);

namespace App\Erp\Business;

use App\Erp\Settings\Setting;

/**
 * Runtime feature gate. Reads the active database's `company.business_type`
 * setting and answers "is this feature / app / model visible here?".
 *
 * The setting is per-workspace (each tenant has its own
 * `ir_config_parameter` table), so the same code shows recipes in Sweileh
 * Café and hides them in Kaleem Perfumes with no per-tenant branching.
 *
 * Fail-open: a database with no type configured (the legacy state, and every
 * install before this feature shipped) reports EVERY feature as enabled, so
 * nothing a business already uses disappears until they deliberately pick a
 * type.
 */
final class Features
{
    /**
     * Application module name → the feature that governs its visibility in
     * the top app bar. Modules absent from this map (contacts, whatsapp, …)
     * are always shown.
     *
     * @var array<string, Feature>
     */
    private const MODULE_FEATURE = [
        'pos' => Feature::Pos,
        'inventory' => Feature::Inventory,
        'purchases' => Feature::Purchases,
        'accounting' => Feature::Accounting,
        'project' => Feature::Projects,
        'rental' => Feature::Bookings,
        'limousine' => Feature::Limousine,
    ];

    /**
     * Registered ir_model id → the feature that governs its menu entry.
     * Models absent from this map are always shown.
     *
     * @var array<string, Feature>
     */
    private const MODEL_FEATURE = [
        'pos.ingredient' => Feature::Recipes,
        // The damage / waste log is stock-keeping, so it follows Inventory —
        // visible in Café, Retail and Retail+Crafting, hidden everywhere with
        // no stock (Rental / Limousine / Services).
        'pos.damage' => Feature::Inventory,
        // Floors & tables are dine-in only — a retail / crafting shop has no
        // table service, so they follow the Restaurant feature (Café only).
        'pos.floor' => Feature::Restaurant,
        'pos.table' => Feature::Restaurant,
    ];

    /**
     * Per-app feature toggles surfaced in that app's own "Settings" tab
     * (`/app/{module}/settings`). Only the SUB-features of an app appear — the
     * app-level master feature is intentionally absent (you can't disable the
     * very app you're inside). An app with no entry here has no Settings tab.
     *
     * @var array<string, list<Feature>>
     */
    private const APP_FEATURES = [
        'pos' => [Feature::Restaurant, Feature::Recipes],
    ];

    /** The settings key holding manual per-feature overrides (JSON object). */
    private const OVERRIDES_KEY = 'features.overrides';

    /**
     * The configured type for the active database, or null when none has
     * been chosen (→ treat everything as enabled).
     */
    public static function configuredType(): ?BusinessType
    {
        $raw = Setting::get('company.business_type');

        return is_string($raw) && $raw !== ''
            ? BusinessType::tryFrom($raw)
            : null;
    }

    /**
     * Whether a feature is on RIGHT NOW: a manual override (set in an app's
     * Settings tab) wins; otherwise the business-type preset decides; a
     * database with neither configured fails open (legacy-safe).
     */
    public static function enabled(Feature $feature): bool
    {
        $overrides = self::overrides();
        if (array_key_exists($feature->value, $overrides)) {
            return $overrides[$feature->value];
        }

        return self::presetEnabled($feature);
    }

    /** The business-type preset value for a feature, ignoring manual overrides. */
    public static function presetEnabled(Feature $feature): bool
    {
        $type = self::configuredType();

        // Unconfigured database → fail open (legacy-safe).
        if ($type === null) {
            return true;
        }

        return in_array($feature, $type->features(), true);
    }

    /**
     * The sub-features an app exposes in its own Settings tab (empty = the app
     * has no toggles, so no Settings tab).
     *
     * @return list<Feature>
     */
    public static function appFeatures(string $module): array
    {
        return self::APP_FEATURES[$module] ?? [];
    }

    /**
     * Manual overrides: feature value → forced on/off. Read from the
     * per-workspace `features.overrides` setting (a JSON object).
     *
     * @return array<string, bool>
     */
    public static function overrides(): array
    {
        $raw = Setting::get(self::OVERRIDES_KEY);
        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key)) {
                $out[$key] = (bool) $value;
            }
        }

        return $out;
    }

    /**
     * Persist manual overrides for the given features (merged with any
     * existing ones), flushing the settings cache so the new state takes
     * effect immediately.
     *
     * @param array<string, bool> $values feature value => enabled
     */
    public static function setOverrides(array $values): void
    {
        $merged = self::overrides();
        foreach ($values as $key => $enabled) {
            $merged[$key] = (bool) $enabled;
        }

        Setting::set(self::OVERRIDES_KEY, json_encode($merged, JSON_THROW_ON_ERROR));
    }

    /** Whether an application module should appear in the top app bar. */
    public static function moduleAllowed(string $module): bool
    {
        $feature = self::MODULE_FEATURE[$module] ?? null;

        return $feature === null || self::enabled($feature);
    }

    /** Whether a registered model should appear in its module menu. */
    public static function modelAllowed(string $model): bool
    {
        $feature = self::MODEL_FEATURE[$model] ?? null;

        return $feature === null || self::enabled($feature);
    }
}
