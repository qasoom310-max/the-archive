<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * A trip's place is chosen country first, then city: the saved locations
 * carry a country, and each leg remembers the country of its pick-up and
 * drop-off (ISO-2, e.g. BH, SA).
 *
 * Existing saved locations are Bahrain unless their name is a well-known
 * city elsewhere in the Gulf. Existing legs are left blank: the form works
 * the country out from the place name when it opens one.
 */
return new class extends Migration
{
    /** @var array<string, string> lower-case place name → ISO-2 */
    private const ELSEWHERE = [
        'riyadh' => 'SA', 'jeddah' => 'SA', 'jedda' => 'SA', 'dammam' => 'SA', 'khobar' => 'SA',
        'al khobar' => 'SA', 'alkhobar' => 'SA', 'dhahran' => 'SA', 'makkah' => 'SA', 'mecca' => 'SA',
        'madinah' => 'SA', 'medina' => 'SA', 'al ahsa' => 'SA', 'alahsa' => 'SA', 'al hasa' => 'SA',
        'hofuf' => 'SA', 'jubail' => 'SA', 'qatif' => 'SA', 'taif' => 'SA', 'abha' => 'SA', 'tabuk' => 'SA',
        'dammam airport' => 'SA', 'king fahd airport' => 'SA', 'riyadh airport' => 'SA', 'jeddah airport' => 'SA',
        'doha' => 'QA', 'doha airport' => 'QA',
        'dubai' => 'AE', 'abu dhabi' => 'AE', 'sharjah' => 'AE', 'dubai airport' => 'AE',
        'kuwait' => 'KW', 'kuwait city' => 'KW', 'kuwait airport' => 'KW',
        'muscat' => 'OM', 'muscat airport' => 'OM',
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('limo_locations', 'country')) {
            Schema::table('limo_locations', function (Blueprint $table): void {
                $table->string('country', 2)->nullable()->after('area');
            });
        }

        if (! Schema::hasColumn('limo_legs', 'from_country')) {
            Schema::table('limo_legs', function (Blueprint $table): void {
                $table->string('from_country', 2)->nullable()->after('from_location');
                $table->string('to_country', 2)->nullable()->after('to_location');
            });
        }

        $moved = [];
        foreach (DB::table('limo_locations')->whereNull('country')->get(['id', 'name']) as $row) {
            $key = strtolower(trim(preg_replace('/\s+/', ' ', (string) $row->name) ?? ''));
            $country = self::ELSEWHERE[$key] ?? 'BH';
            DB::table('limo_locations')->where('id', $row->id)->update(['country' => $country]);
            if ($country !== 'BH') {
                $moved[] = $row->name . ' → ' . $country;
            }
        }

        if ($moved !== []) {
            Log::info('Limousine locations given a country other than Bahrain: ' . implode(', ', $moved));
        }
    }

    public function down(): void
    {
        Schema::table('limo_legs', function (Blueprint $table): void {
            $table->dropColumn(['from_country', 'to_country']);
        });
        Schema::table('limo_locations', function (Blueprint $table): void {
            $table->dropColumn('country');
        });
    }
};
