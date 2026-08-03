<?php

declare(strict_types=1);

namespace Modules\Pos\Services;

use App\Erp\Settings\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Pos\Enums\PrepStation;
use Modules\Pos\Models\PosProduct;

/**
 * Sweileh Café late-night happy hour.
 *
 * Between midnight and 6 AM **Bahrain time**, and ONLY in the Sweileh Café
 * database:
 *   - shisha (any product whose category routes to the KDS Shisha station)
 *     drops to a flat {@see SHISHA_PRICE} whatever its normal price;
 *   - everything else — food & drinks — gets {@see FOOD_DISCOUNT_PERCENT}% off.
 *
 * The deal is applied per cart line at RING-UP (in
 * {@see \Modules\Pos\Livewire\PosTerminal::addProduct()}): the price you get is
 * the price in force the moment the item is added, so a line rung up at 00:30
 * keeps the deal even if the bill is settled after 06:00, and a line rung up at
 * 23:50 stays full price. Times are always evaluated in Asia/Bahrain, never the
 * server/app timezone.
 *
 * Scope is hardcoded to Sweileh Café by matching the database's `company.name`
 * setting (per-workspace), tolerant of the "Café"/"Cafe" spelling and the
 * sweileh/swelieh transposition — see {@see isSweilehCafe()}.
 */
final class HappyHour
{
    /** Flat price every shisha item drops to during the window (BHD). */
    public const SHISHA_PRICE = 1.4;

    /** Percentage off food & drinks during the window. */
    public const FOOD_DISCOUNT_PERCENT = 25.0;

    /** Window is [START_HOUR, END_HOUR) in Bahrain local time. */
    private const START_HOUR = 0;

    private const END_HOUR = 6;

    /** Bahrain is UTC+03 year-round (no DST), so the hour test is stable. */
    private const TIMEZONE = 'Asia/Bahrain';

    /** Whether the deal is live right now: the right database AND inside the window. */
    public function active(): bool
    {
        return $this->isSweilehCafe() && $this->withinWindow(Carbon::now());
    }

    /**
     * Is the given instant inside the midnight–6 AM window in Bahrain local
     * time? Isolated from {@see active()} so it can be unit-tested without
     * touching settings.
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
     * The (unit price, line discount %) a NEW cart line for this product should
     * carry. When the window is closed (or this isn't Sweileh Café) it's simply
     * the product's own price at 0% — i.e. unchanged behaviour.
     *
     * @return array{unit_price: float, discount: float}
     */
    public function priceLine(PosProduct $product): array
    {
        if (! $this->active()) {
            return ['unit_price' => (float) $product->price, 'discount' => 0.0];
        }

        if ($this->isShisha($product)) {
            return ['unit_price' => self::SHISHA_PRICE, 'discount' => 0.0];
        }

        return ['unit_price' => (float) $product->price, 'discount' => self::FOOD_DISCOUNT_PERCENT];
    }
}
