<?php

declare(strict_types=1);

namespace Modules\Pos\Support;

use InvalidArgumentException;

/**
 * Country code catalogue for the POS receipt phone picker. Kept tiny and
 * explicit on purpose — the user picked these in the Phase-10 spec
 * (Bahrain default + Qatar/Oman/Jordan/Egypt/Lebanon). To add a country,
 * extend the {@see COUNTRIES} list and the dropdown picks it up on the
 * next request.
 *
 * `dial` is the human-readable form with '+' (`+973`); `digits` is what
 * goes into the composed E.164 number (`973`) before joining with the
 * local digits — Meta's Graph API expects digits only, no '+'.
 */
final class PosWhatsAppCountries
{
    /**
     * @var list<array{dial: string, digits: string, label: string}>
     */
    public const COUNTRIES = [
        ['dial' => '+973', 'digits' => '973', 'label' => 'Bahrain (+973)'],
        ['dial' => '+966', 'digits' => '966', 'label' => 'Saudi Arabia (+966)'],
        ['dial' => '+971', 'digits' => '971', 'label' => 'UAE (+971)'],
        ['dial' => '+965', 'digits' => '965', 'label' => 'Kuwait (+965)'],
        ['dial' => '+974', 'digits' => '974', 'label' => 'Qatar (+974)'],
        ['dial' => '+968', 'digits' => '968', 'label' => 'Oman (+968)'],
        ['dial' => '+962', 'digits' => '962', 'label' => 'Jordan (+962)'],
        ['dial' => '+20',  'digits' => '20',  'label' => 'Egypt (+20)'],
        ['dial' => '+961', 'digits' => '961', 'label' => 'Lebanon (+961)'],
        ['dial' => '+967', 'digits' => '967', 'label' => 'Yemen (+967)'],
        ['dial' => '+964', 'digits' => '964', 'label' => 'Iraq (+964)'],
        ['dial' => '+91',  'digits' => '91',  'label' => 'India (+91)'],
        ['dial' => '+92',  'digits' => '92',  'label' => 'Pakistan (+92)'],
    ];

    /**
     * The default dial code presented to the cashier when the payment
     * overlay opens (Bahrain — the primary deployment).
     */
    public const DEFAULT_DIAL = '+973';

    /**
     * @return list<array{dial: string, digits: string, label: string}>
     */
    public static function all(): array
    {
        return self::COUNTRIES;
    }

    /**
     * Is `$dial` (e.g. "+973") in the configured list?
     */
    public static function isValidDial(string $dial): bool
    {
        foreach (self::COUNTRIES as $country) {
            if ($country['dial'] === $dial) {
                return true;
            }
        }

        return false;
    }

    /**
     * Compose `+<countryDigits><localDigits>` into a Meta-ready string of
     * digits only (no '+', no spaces, no leading zero on the local part).
     * Returns null if either side is empty after stripping non-digits, so
     * the caller can treat "no phone provided" as a clean skip.
     *
     * Local-number leading-zero rule: most national mobile formats are
     * recorded with a trunk prefix `0` that must be dropped in
     * international form (e.g. Egypt 010… → 20 10…). We strip exactly
     * one leading zero from the local part.
     */
    public static function compose(string $dial, string $localPhone): ?string
    {
        if (! self::isValidDial($dial)) {
            throw new InvalidArgumentException("Unknown country dial code: {$dial}");
        }

        $countryDigits = preg_replace('/\D+/', '', $dial) ?? '';
        $localDigits = preg_replace('/\D+/', '', $localPhone) ?? '';

        if ($countryDigits === '' || $localDigits === '') {
            return null;
        }

        if (str_starts_with($localDigits, '0')) {
            $localDigits = substr($localDigits, 1);

            if ($localDigits === '') {
                return null;
            }
        }

        return $countryDigits . $localDigits;
    }
}
