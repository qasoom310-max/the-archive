<?php

declare(strict_types=1);

namespace App\Erp\Pricing;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The published-fares version for THIS database.
 *
 * The website uses it as an ETag, so it must change exactly when the fares
 * change — once per save, never once per row. A grid save touches ~20 rate
 * rows; a model observer would bump it twenty times and make every cached
 * copy on the website stale twenty times over.
 *
 * So every write goes through {@see PricingWriter::transaction()}, which calls
 * {@see bump()} once inside the same transaction.
 */
final class PricingVersion
{
    private const TABLE = 'pricing_version';

    public static function current(): int
    {
        if (! Schema::hasTable(self::TABLE)) {
            return 0;
        }

        $row = DB::table(self::TABLE)->orderBy('id')->first();

        return $row === null ? 0 : (int) $row->version;
    }

    public static function updatedAt(): ?CarbonImmutable
    {
        if (! Schema::hasTable(self::TABLE)) {
            return null;
        }

        $row = DB::table(self::TABLE)->orderBy('id')->first();

        if ($row === null || $row->updated_at === null) {
            return null;
        }

        return CarbonImmutable::parse((string) $row->updated_at);
    }

    /**
     * Raise the version by exactly one. Call inside the caller's transaction —
     * a bump that survives a rolled-back save would tell the website to fetch
     * a change that never happened.
     */
    public static function bump(): int
    {
        $now = CarbonImmutable::now();
        $row = DB::table(self::TABLE)->orderBy('id')->lockForUpdate()->first();

        if ($row === null) {
            DB::table(self::TABLE)->insert([
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return 1;
        }

        $next = (int) $row->version + 1;

        DB::table(self::TABLE)->where('id', $row->id)->update([
            'version' => $next,
            'updated_at' => $now,
        ]);

        return $next;
    }
}
