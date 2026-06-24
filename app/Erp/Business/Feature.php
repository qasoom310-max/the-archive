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

    // POS sub-features (toggled from POS → Settings).
    case Condiments = 'condiments';
    case CustomerDiscounts = 'customer_discounts';
    case Damage = 'damage';
    case BarcodeScanning = 'barcode_scanning';
    // Postpaid dine-in: fire items to the kitchen on add and pay at the end.
    // OFF (the default) = prepaid: pay first, then the order fires to the
    // kitchen. Opt-in — see Features::DEFAULT_OFF.
    case Postpaid = 'postpaid';

    // Accounting sub-features.
    case ChartOfAccounts = 'chart_of_accounts';
    case JournalEntries = 'journal_entries';

    // Rent A Car sub-features.
    case RentalMaintenance = 'rental_maintenance';
    case RentalDrivers = 'rental_drivers';
    case RentalQuotations = 'rental_quotations';
    case RentalReplacements = 'rental_replacements';

    // Limousine sub-features.
    case LimoExpenses = 'limo_expenses';
    case LimoQuotations = 'limo_quotations';
    case LimoLocations = 'limo_locations';

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
            self::Condiments => 'Condiments & add-ons',
            self::CustomerDiscounts => 'Customer discounts',
            self::Damage => 'Damage / waste log',
            self::BarcodeScanning => 'Barcode scanning (camera)',
            self::Postpaid => 'Postpaid (kitchen first, pay later)',
            self::ChartOfAccounts => 'Chart of accounts',
            self::JournalEntries => 'Journal entries',
            self::RentalMaintenance => 'Vehicle maintenance',
            self::RentalDrivers => 'Drivers',
            self::RentalQuotations => 'Rental quotations',
            self::RentalReplacements => 'Replacement vehicles',
            self::LimoExpenses => 'Trip expenses',
            self::LimoQuotations => 'Limousine quotations',
            self::LimoLocations => 'Saved locations',
        };
    }
}
