<?php

declare(strict_types=1);

namespace Modules\Pos\Services;

use App\Erp\Settings\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Pos\Enums\PrepStation;
use Modules\Pos\Models\PosCategory;
use Modules\Pos\Models\PosProduct;

/**
 * Sweileh Café afternoon happy hour.
 *
 * Between noon (12 PM) and 6 PM **Bahrain time**, and ONLY in the Sweileh Café
 * database:
 *   - shisha (any product whose category routes to the KDS Shisha station) is
 *     CAPPED at {@see SHISHA_PRICE} — a 1.600 shisha drops to 1.400, but one
 *     already priced below the cap (e.g. Zaglol at 1.200) keeps its price. The
 *     deal only ever lowers a price, never raises it;
 *   - food gets {@see FOOD_DISCOUNT_PERCENT}% off;
 *   - drinks (any product in a category NAMED "Drinks") are EXCLUDED — no
 *     discount;
 *   - sweets (any product in a category NAMED "Sweets") are likewise EXCLUDED
 *     — no discount.
 *
 * The deal is applied per cart line at RING-UP (in
 * {@see \Modules\Pos\Livewire\PosTerminal::addProduct()}): the price you get is
 * the price in force the moment the item is added, so a line rung up at 12:30
 * keeps the deal even if the bill is settled after 18:00, and a line rung up at
 * 11:50 stays full price. Times are always evaluated in Asia/Bahrain, never the
 * server/app timezone.
 *
 * Scope is hardcoded to Sweileh Café by matching the database's `company.name`
 * setting (per-workspace), tolerant of the "Café"/"Cafe" spelling and the
 * sweileh/swelieh transposition — see {@see isSweilehCafe()}.
 */
final class HappyHour
{
    /** Price cap for shisha during the window (BHD) — never raises a cheaper one. */
    public const SHISHA_PRICE = 1.4;

    /** Percentage off FOOD during the window (drinks are excluded). */
    public const FOOD_DISCOUNT_PERCENT = 25.0;

    /** Window is [START_HOUR, END_HOUR) in Bahrain local time — noon to 6 PM. */
    private const START_HOUR = 12;

    private const END_HOUR = 18;

    /** Bahrain is UTC+03 year-round (no DST), so the hour test is stable. */
    private const TIMEZONE = 'Asia/Bahrain';

    /** Whether the deal is live right now: the right database AND inside the window. */
    public function active(): bool
    {
        return $this->isSweilehCafe() && $this->withinWindow(Carbon::now());
    }

    /**
     * Is the given instant inside the noon–6 PM window in Bahrain local time?
     * Isolated from {@see active()} so it can be unit-tested without touching
     * settings.
     */
    public function withinWindow(Carbon $instant): bool
    {
        $hour = $instant->copy()->setTimezone(self::TIMEZONE)->hour;

        return $hour >= self::START_HOUR && $hour < self::END_HOUR;
    }

    /**
     * Is THIS database Sweileh Café? Matched on the company name (a per-database
     * setting), normalised to letters only and checked for the café identity so
     * "Sweileh Café", "Swelieh Cafe", "sweileh  cafe" etc. all match.
     */
    public function isSweilehCafe(): bool
    {
        $name = Setting::get('company.name');
        if (! is_string($name) || $name === '') {
            return false;
        }

        $norm = Str::lower((string) preg_replace('/[^a-zA-Z]/', '', $name));

        return str_contains($norm, 'sweileh') || str_contains($norm, 'swelieh');
    }

    /**
     * A shisha product is one whose category routes to the KDS Shisha station —
     * the same signal the kitchen display uses, so no separate tagging.
     */
    public function isShisha(PosProduct $product): bool
    {
        return $product->category?->station === PrepStation::Shisha;
    }

    /**
     * A drink is a product whose category is NAMED "Drinks" (case-insensitive,
     * any locale — so "Drinks", "drinks", "Hot Drinks", "Cold Drinks" all match)
     * — excluded from the food discount. Everything that isn't shisha, a drink
     * or a sweet counts as food.
     *
     * Matching on the name rather than a per-category flag keeps this a
     * Sweileh-only concept: `isDrink()` is only ever consulted while the
     * (Sweileh-gated) window is active, so no other database sees any effect and
     * no category checkbox is needed anywhere.
     */
    public function isDrink(PosProduct $product): bool
    {
        return $this->categoryNameContains($product, 'drink');
    }

    /**
     * A sweet is a product whose category is NAMED "Sweets" (case-insensitive,
     * any locale — so "Sweets", "sweets", "Arabic Sweets" all match) — excluded
     * from the food discount, same reasoning and same matching rule as
     * {@see isDrink()}.
     */
    public function isSweet(PosProduct $product): bool
    {
        // Named any way the menu does — "Sweets", "Desserts", "حلويات" — and
        // found on a parent too, so "Kunafa" under "Sweets" counts.
        foreach ($this->categoryChainNames($product) as $name) {
            foreach (self::SWEET_WORDS as $word) {
                if (str_contains($name, $word)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Words that make a category a sweets one, in any of its translations. */
    private const SWEET_WORDS = ['sweet', 'dessert', 'حلويات', 'حلوى', 'حلى', 'تحلية'];

    /**
     * Every name (all translations, lower-cased) of the product's category and
     * the categories above it. Cycle-safe.
     *
     * @return list<string>
     */
    private function categoryChainNames(PosProduct $product): array
    {
        $names = [];
        $seen = [];
        $category = $product->category;
        while ($category !== null && ! isset($seen[$category->id])) {
            $seen[$category->id] = true;
            foreach ([...array_values($category->getTranslations('name')), (string) $category->name] as $name) {
                $names[] = Str::lower((string) $name);
            }
            $category = $category->parent_id !== null ? PosCategory::query()->find($category->parent_id) : null;
        }

        return $names;
    }

    /**
     * Does this product's category name (any translation) contain $needle?
     */
    private function categoryNameContains(PosProduct $product, string $needle): bool
    {
        $category = $product->category;
        if ($category === null) {
            return false;
        }

        // The name is a translatable JSON envelope; check every locale value
        // (plus the resolved active-locale name, in case of a plain-string row).
        $names = array_values($category->getTranslations('name'));
        $names[] = (string) $category->name;

        foreach ($names as $name) {
            if (str_contains(Str::lower((string) $name), $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The (unit price, line discount %) a NEW cart line for this product should
     * carry. When the window is closed (or this isn't Sweileh Café) it's simply
     * the product's own price at 0% — i.e. unchanged behaviour.
     *
     * @return array{unit_price: float, discount: float}
     */
    public function priceLine(PosProduct $product): array
    {
        $price = (float) $product->price;

        if (! $this->active()) {
            return ['unit_price' => $price, 'discount' => 0.0];
        }

        // Shisha: cap at the deal price, but never RAISE a cheaper one (Zaglol
        // at 1.200 stays 1.200; a 1.600 shisha drops to 1.400).
        if ($this->isShisha($product)) {
            return ['unit_price' => min($price, self::SHISHA_PRICE), 'discount' => 0.0];
        }

        // Drinks and sweets are excluded from the food discount.
        if ($this->isDrink($product) || $this->isSweet($product)) {
            return ['unit_price' => $price, 'discount' => 0.0];
        }

        // Food.
        return ['unit_price' => $price, 'discount' => self::FOOD_DISCOUNT_PERCENT];
    }
}
