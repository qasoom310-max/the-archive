<?php

declare(strict_types=1);

namespace App\Erp\Business;

/**
 * A toggleable capability of the ERP. Each {@see BusinessType} enables a
 * subset of these; the active database's type therefore decides which apps,
 * menus and on-form widgets a user sees. Adding a feature is one case here
 * plus a mapping in {@see Features} (module and/or model).
 */
enum Feature: string
{
    case Pos = 'pos';
    case Recipes = 'recipes';
    case Inventory = 'inventory';
    case Purchases = 'purchases';
    case Hr = 'hr';
    case Accounting = 'accounting';
    case Bookings = 'bookings';
    case Limousine = 'limousine';
    case Projects = 'projects';
    case Restaurant = 'restaurant';

    public function label(): string
    {
        return match ($this) {
            self::Pos => 'Point of Sale',
            self::Recipes => 'Recipes & ingredients',
            self::Inventory => 'Inventory',
            self::Purchases => 'Purchases',
            self::Hr => 'HR & payroll',
            self::Accounting => 'Accounting',
            self::Bookings => 'Bookings & rentals',
            self::Limousine => 'Limousine',
            self::Projects => 'Projects',
            self::Restaurant => 'Dine-in (tables, kitchen, shisha)',
        };
    }
}
