<?php

declare(strict_types=1);

namespace App\Erp\Money;

use App\Erp\Settings\Setting;

/**
 * Currency registry — the catalogue every money display routes through.
 *
 * Heavy on Arab-world currencies (full GCC + Levant + Maghreb + Horn)
 * because that's the ERP's primary market, plus a small set of major
 * trading currencies (USD/EUR/GBP/INR/PKR/TRY) so cross-border use stays
 * possible. ISO 4217 codes drive the registry; the {@see Currency} value
 * object carries the display symbol, decimal precision, and position.
 *
 * The active currency comes from {@see Setting::get('currency.default')}
 * (Phase 8 ir_config_parameter), with USD as the safe fallback when the
 * value is missing or names an unknown code.
 */
final class Currencies
{
    /** @var array<string, Currency>|null memo so each request builds the list once */
    private static ?array $cache = null;

    /** ISO 4217 code used as the silent fallback when the setting is empty/invalid. */
    public const FALLBACK = 'USD';

    /** @return array<string, Currency> keyed by uppercase ISO code */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        // Decimals: 2 across the board for consistent UI rendering
        // (0.45 not 0.450), except DJF/KMF which are exponent-0 in ISO
        // 4217 — no fractional unit exists. ISO 4217 spec'd the dinars
        // (BHD/KWD/OMR/JOD/LYD/TND/IQD) at 3, but our deployments don't
        // need fils-level precision and the extra zero is visual noise.
        // Symbols use the abbreviation most commonly rendered in Latin
        // script — keeps the UI legible without forcing an Arabic-only
        // font stack.
        $list = [
            new Currency('BHD', 'Bahraini Dinar',       'BD',   2),
            new Currency('KWD', 'Kuwaiti Dinar',        'KD',   2),
            new Currency('OMR', 'Omani Rial',           'OMR',  2),
            new Currency('JOD', 'Jordanian Dinar',      'JD',   2),
            new Currency('LYD', 'Libyan Dinar',         'LD',   2),
            new Currency('TND', 'Tunisian Dinar',       'DT',   2),
            new Currency('IQD', 'Iraqi Dinar',          'IQD',  2),
            new Currency('SAR', 'Saudi Riyal',          'SR',   2),
            new Currency('QAR', 'Qatari Riyal',         'QR',   2),
            new Currency('AED', 'UAE Dirham',           'AED',  2),
            new Currency('LBP', 'Lebanese Pound',       'LBP',  2),
            new Currency('SYP', 'Syrian Pound',         'SYP',  2),
            new Currency('YER', 'Yemeni Rial',          'YER',  2),
            new Currency('EGP', 'Egyptian Pound',       'E£',   2),
            new Currency('SDG', 'Sudanese Pound',       'SDG',  2),
            new Currency('DZD', 'Algerian Dinar',       'DA',   2),
            new Currency('MAD', 'Moroccan Dirham',      'DH',   2),
            new Currency('MRU', 'Mauritanian Ouguiya',  'UM',   2),
            new Currency('SOS', 'Somali Shilling',      'Sh',   2),
            new Currency('DJF', 'Djiboutian Franc',     'Fdj',  0),
            new Currency('KMF', 'Comorian Franc',       'CF',   0),
            new Currency('USD', 'US Dollar',            '$',    2, Currency::POSITION_BEFORE),
            new Currency('EUR', 'Euro',                 '€',    2, Currency::POSITION_BEFORE),
            new Currency('GBP', 'Pound Sterling',       '£',    2, Currency::POSITION_BEFORE),
            new Currency('INR', 'Indian Rupee',         '₹',    2, Currency::POSITION_BEFORE),
            new Currency('PKR', 'Pakistani Rupee',      'Rs',   2),
            new Currency('TRY', 'Turkish Lira',         '₺',    2, Currency::POSITION_BEFORE),
        ];

        $byCode = [];
        foreach ($list as $c) {
            $byCode[$c->code] = $c;
        }

        return self::$cache = $byCode;
    }

    /** Reset memo — used by tests that mutate the catalogue between cases. */
    public static function flushCache(): void
    {
        self::$cache = null;
    }

    public static function find(string $code): ?Currency
    {
        return self::all()[strtoupper($code)] ?? null;
    }

    public static function isValid(string $code): bool
    {
        return self::find($code) !== null;
    }

    /**
     * The currency currently selected in Settings → General → Default
     * currency. Falls back to USD if the value is empty or names a code
     * we don't carry — so a misconfigured setting never throws.
     */
    public static function active(): Currency
    {
        $code = (string) Setting::get('currency.default', self::FALLBACK);
        $currency = self::find($code);

        return $currency ?? self::find(self::FALLBACK) ?? new Currency(self::FALLBACK, 'US Dollar', '$', 2, Currency::POSITION_BEFORE);
    }

    /**
     * Format an amount using the active currency (default) or an
     * override code. The single entry point every money display in the
     * app routes through.
     */
    public static function format(float|int|string|null $amount, ?string $code = null): string
    {
        $currency = $code === null ? self::active() : (self::find($code) ?? self::active());

        return $currency->format($amount);
    }
}
