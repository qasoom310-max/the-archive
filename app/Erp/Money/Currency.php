<?php

declare(strict_types=1);

namespace App\Erp\Money;

/**
 * One row in the currency registry — an ISO 4217 code paired with the
 * display conventions we use to format an amount: the user-facing symbol
 * (e.g. "BD" for the Bahraini Dinar), the number of fractional digits
 * the currency canonically uses (BHD = 3, USD = 2, DJF = 0), and whether
 * the symbol sits before or after the amount in Latin-script display.
 *
 * The list of instances lives in {@see Currencies}; this object is a
 * value-type, never persisted.
 */
final readonly class Currency
{
    public const POSITION_BEFORE = 'before';

    public const POSITION_AFTER = 'after';

    public function __construct(
        public string $code,
        public string $name,
        public string $symbol,
        public int $decimals,
        public string $position = self::POSITION_AFTER,
    ) {
    }

    /** Human label for a settings dropdown: "BHD — Bahraini Dinar". */
    public function label(): string
    {
        return $this->code . ' — ' . $this->name;
    }

    /**
     * Format a scalar amount with this currency's conventions. Padding
     * to the right number of decimals is delegated to PHP's locale-free
     * `number_format` so callers don't need to know the precision.
     */
    public function format(float|int|string|null $amount): string
    {
        $value = $amount === null ? 0.0 : (float) $amount;
        $body = number_format($value, $this->decimals, '.', ',');

        return $this->position === self::POSITION_BEFORE
            ? $this->symbol . $body
            : $body . ' ' . $this->symbol;
    }
}
