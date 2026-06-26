<?php

declare(strict_types=1);

namespace App\Models\Concerns;

/**
 * Country handling for models with an ISO-2 `country` column: the flag emoji
 * accessor, the canonical selectable-country list (Bahrain-first, with dial
 * codes), and the human country name. Shared so the Rental and Limousine
 * customers behave identically without one reaching into the other.
 */
trait HasCountryFlag
{
    /** Country flag emoji from the ISO-2 country code (e.g. BH → 🇧🇭). */
    public function getFlagAttribute(): string
    {
        $country = $this->getAttribute('country');

        return self::flagFor(is_string($country) ? $country : null);
    }

    public static function flagFor(?string $code): string
    {
        if ($code === null || strlen($code) !== 2 || ! ctype_alpha($code)) {
            return '';
        }

        $code = strtoupper($code);
        $a = mb_chr(0x1F1E6 + ord($code[0]) - ord('A'));
        $b = mb_chr(0x1F1E6 + ord($code[1]) - ord('A'));

        return ($a !== false ? $a : '') . ($b !== false ? $b : '');
    }

    /** Human country name for the stored ISO-2 code. */
    public function countryName(): ?string
    {
        $country = $this->getAttribute('country');

        foreach (self::countries() as $c) {
            if ($c['code'] === $country) {
                return $c['name'];
            }
        }

        return null;
    }

    /**
     * Selectable countries with their dial code — Bahrain-first, then the GCC
     * and the most common expat origins. The flag is derived from the code.
     *
     * @return list<array{code: string, name: string, dial: string}>
     */
    public static function countries(): array
    {
        return [
            ['code' => 'BH', 'name' => 'Bahrain', 'dial' => '+973'],
            ['code' => 'SA', 'name' => 'Saudi Arabia', 'dial' => '+966'],
            ['code' => 'AE', 'name' => 'United Arab Emirates', 'dial' => '+971'],
            ['code' => 'KW', 'name' => 'Kuwait', 'dial' => '+965'],
            ['code' => 'QA', 'name' => 'Qatar', 'dial' => '+974'],
            ['code' => 'OM', 'name' => 'Oman', 'dial' => '+968'],
            ['code' => 'EG', 'name' => 'Egypt', 'dial' => '+20'],
            ['code' => 'JO', 'name' => 'Jordan', 'dial' => '+962'],
            ['code' => 'LB', 'name' => 'Lebanon', 'dial' => '+961'],
            ['code' => 'SY', 'name' => 'Syria', 'dial' => '+963'],
            ['code' => 'IQ', 'name' => 'Iraq', 'dial' => '+964'],
            ['code' => 'YE', 'name' => 'Yemen', 'dial' => '+967'],
            ['code' => 'SD', 'name' => 'Sudan', 'dial' => '+249'],
            ['code' => 'IN', 'name' => 'India', 'dial' => '+91'],
            ['code' => 'PK', 'name' => 'Pakistan', 'dial' => '+92'],
            ['code' => 'BD', 'name' => 'Bangladesh', 'dial' => '+880'],
            ['code' => 'LK', 'name' => 'Sri Lanka', 'dial' => '+94'],
            ['code' => 'NP', 'name' => 'Nepal', 'dial' => '+977'],
            ['code' => 'PH', 'name' => 'Philippines', 'dial' => '+63'],
            ['code' => 'ID', 'name' => 'Indonesia', 'dial' => '+62'],
            ['code' => 'GB', 'name' => 'United Kingdom', 'dial' => '+44'],
            ['code' => 'US', 'name' => 'United States', 'dial' => '+1'],
            ['code' => 'CA', 'name' => 'Canada', 'dial' => '+1'],
            ['code' => 'FR', 'name' => 'France', 'dial' => '+33'],
            ['code' => 'DE', 'name' => 'Germany', 'dial' => '+49'],
            ['code' => 'TR', 'name' => 'Türkiye', 'dial' => '+90'],
            ['code' => 'IR', 'name' => 'Iran', 'dial' => '+98'],
        ];
    }
}
