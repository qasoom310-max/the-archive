<?php

declare(strict_types=1);

namespace App\Erp\Business;

/**
 * The kind of business a database serves. Stored per-database in the
 * `company.business_type` setting (so each workspace — Sweileh Café, Kaleem
 * Perfumes, the car-rental book — picks its own). The chosen type expands to
 * a preset of {@see Feature}s, which {@see Features} consults to hide apps and
 * menus that don't apply.
 *
 * A database with NO type set is treated as "everything on" by {@see Features}
 * so existing installs are never silently stripped of features — only an
 * explicit choice narrows the surface.
 */
enum BusinessType: string
{
    case Cafe = 'cafe';
    case Retail = 'retail';
    case RetailCraft = 'retail_craft';
    case Rental = 'rental';
    case Limousine = 'limousine';
    case Services = 'services';
    case General = 'general';

    /** Human label for the settings picker. */
    public function label(): string
    {
        return match ($this) {
            self::Cafe => 'Café / Restaurant',
            self::Retail => 'Retail / Shop',
            self::RetailCraft => 'Retail + Crafting (resell & make your own)',
            self::Rental => 'Rental (cars, equipment)',
            self::Limousine => 'Limousine service',
            self::Services => 'Services / Agency',
            self::General => 'General (all features)',
        };
    }

    /**
     * The features this type switches on.
     *
     * @return list<Feature>
     */
    public function features(): array
    {
        return match ($this) {
            // A café sells made-to-order items: full POS with recipes that
            // consume stock, plus purchasing and inventory behind them.
            self::Cafe => [
                Feature::Pos, Feature::Recipes, Feature::Inventory,
                Feature::Purchases, Feature::Hr, Feature::Accounting,
            ],
            // A shop sells finished goods — same POS/stock chain, but no
            // recipes (nothing is assembled from components on sale).
            self::Retail => [
                Feature::Pos, Feature::Inventory,
                Feature::Purchases, Feature::Hr, Feature::Accounting,
            ],
            // A shop that BOTH resells bought goods AND crafts its own from
            // ingredients (e.g. a perfume house: some bottles bought-in, some
            // blended in-house). Retail plus recipes — recipes stay optional
            // per product, so resale items simply have no recipe.
            self::RetailCraft => [
                Feature::Pos, Feature::Recipes, Feature::Inventory,
                Feature::Purchases, Feature::Hr, Feature::Accounting,
            ],
            // Rentals revolve around bookings of assets, not a sales counter.
            self::Rental => [
                Feature::Bookings, Feature::Purchases,
                Feature::Hr, Feature::Accounting,
            ],
            // A limousine service runs on trip bookings, with its own app.
            self::Limousine => [
                Feature::Limousine, Feature::Purchases,
                Feature::Hr, Feature::Accounting,
            ],
            // A service business runs on projects, people and the ledger.
            self::Services => [
                Feature::Projects, Feature::Hr, Feature::Accounting,
            ],
            // Everything — the safe default for a mixed / undecided business.
            self::General => Feature::cases(),
        };
    }

    /**
     * All selectable types, in display order.
     *
     * @return list<self>
     */
    public static function all(): array
    {
        return self::cases();
    }
}
