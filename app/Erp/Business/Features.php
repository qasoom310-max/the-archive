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
    ];

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

    public static function enabled(Feature $feature): bool
    {
        $type = self::configuredType();

        // Unconfigured database → fail open (legacy-safe).
        if ($type === null) {
            return true;
        }

        return in_array($feature, $type->features(), true);
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
