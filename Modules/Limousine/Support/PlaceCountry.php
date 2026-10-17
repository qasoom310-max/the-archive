<?php

declare(strict_types=1);

namespace Modules\Limousine\Support;

use Illuminate\Support\Facades\Schema;
use Modules\Limousine\Models\LimoLocation;

/**
 * Which country a trip's place is in.
 *
 * A trip saved since 17 Oct 2026 carries its country. An older one only has
 * the place as text, so its country is read from the saved place of that
 * name, then from the name of a well-known Gulf city in the text ("Hilton
 * Riyadh", "Dammam airport"), and otherwise it is Bahrain, where the
 * business is.
 */
final class PlaceCountry
{
    /** @var array<string, string> word in a place name → ISO-2 */
    private const CITY_WORDS = [
        'riyadh' => 'SA', 'jeddah' => 'SA', 'jedda' => 'SA', 'dammam' => 'SA', 'khobar' => 'SA',
        'alkhobar' => 'SA', 'dhahran' => 'SA', 'makkah' => 'SA', 'mecca' => 'SA', 'madinah' => 'SA',
        'medina' => 'SA', 'ahsa' => 'SA', 'hasa' => 'SA', 'hofuf' => 'SA', 'jubail' => 'SA', 'qatif' => 'SA',
        'taif' => 'SA', 'abha' => 'SA', 'tabuk' => 'SA', 'saudi' => 'SA', 'ksa' => 'SA', 'khafji' => 'SA',
        'الرياض' => 'SA', 'جدة' => 'SA', 'الدمام' => 'SA', 'الخبر' => 'SA', 'الظهران' => 'SA', 'السعودية' => 'SA',
        'الأحساء' => 'SA', 'الاحساء' => 'SA', 'الجبيل' => 'SA', 'القطيف' => 'SA', 'مكة' => 'SA',
        'doha' => 'QA', 'qatar' => 'QA', 'الدوحة' => 'QA', 'قطر' => 'QA',
        'dubai' => 'AE', 'sharjah' => 'AE', 'دبي' => 'AE', 'الشارقة' => 'AE',
        'kuwait' => 'KW', 'الكويت' => 'KW',
        'muscat' => 'OM', 'oman' => 'OM', 'مسقط' => 'OM',
    ];

    /** @var array<string, string>|null saved place (lower case) → country */
    private ?array $saved = null;

    public function of(?string $stored, ?string $place): string
    {
        $known = LimoLocation::knownCountry($stored);
        if ($known !== null) {
            return $known;
        }

        $name = mb_strtolower(trim((string) $place));
        if ($name === '') {
            return LimoLocation::DEFAULT_COUNTRY;
        }

        $saved = $this->saved();
        if (isset($saved[$name])) {
            return $saved[$name];
        }

        // "Abu Dhabi" is two words, so it is checked as a phrase first.
        if (str_contains($name, 'abu dhabi') || str_contains($name, 'أبوظبي') || str_contains($name, 'ابوظبي')) {
            return 'AE';
        }

        foreach (preg_split('/[^\p{L}\p{N}]+/u', $name) ?: [] as $word) {
            if (isset(self::CITY_WORDS[$word])) {
                return self::CITY_WORDS[$word];
            }
        }

        return LimoLocation::DEFAULT_COUNTRY;
    }

    /**
     * @return array<string, string>
     */
    private function saved(): array
    {
        if ($this->saved !== null) {
            return $this->saved;
        }

        $this->saved = [];
        if (! Schema::hasTable('limo_locations') || ! Schema::hasColumn('limo_locations', 'country')) {
            return $this->saved;
        }

        foreach (LimoLocation::query()->orderBy('id')->get(['name', 'country']) as $loc) {
            $this->saved[mb_strtolower(trim($loc->name))] ??= LimoLocation::knownCountry($loc->country) ?? LimoLocation::DEFAULT_COUNTRY;
        }

        return $this->saved;
    }
}
