<?php

declare(strict_types=1);

namespace App\Erp\Enums;

/**
 * Lifecycle state of a module in the `ir_module` registry.
 * Mirrors the relevant subset of Odoo's `ir.module.module.state`.
 */
enum ModuleState: string
{
    case Uninstalled = 'uninstalled';
    case Installed = 'installed';
    case ToUpgrade = 'to_upgrade';

    public function label(): string
    {
        return match ($this) {
            self::Uninstalled => 'Not installed',
            self::Installed => 'Installed',
            self::ToUpgrade => 'To upgrade',
        };
    }

    /** Tailwind badge colour token used by the UI in later phases. */
    public function color(): string
    {
        return match ($this) {
            self::Uninstalled => 'gray',
            self::Installed => 'emerald',
            self::ToUpgrade => 'amber',
        };
    }

    public function isInstalled(): bool
    {
        return $this === self::Installed;
    }
}
