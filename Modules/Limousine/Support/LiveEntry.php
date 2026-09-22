<?php

declare(strict_types=1);

namespace Modules\Limousine\Support;

use App\Erp\Settings\CompanyTimezone;
use Illuminate\Support\Carbon;

/**
 * The day the office started entering Limousine work in this ERP.
 *
 * The "Live entry data" exports keep only rows created on or after it. The
 * per-row import markers (`imported_at`, the legacy reference shapes) are not
 * enough on their own: `imported_at` was added after the historical import
 * had already run, so every row that import brought over reads as live. A
 * date is the one test that holds for all of them — the legacy importers
 * backdate `created_at` to the old system's own dates, and the earlier bulk
 * migration ran before this day.
 */
final class LiveEntry
{
    /** Local business date, in the company timezone. */
    public const SINCE = '2026-09-15';

    /**
     * Midnight at the start of SINCE in the company timezone, converted to the
     * timezone timestamps are stored in.
     */
    public static function since(): string
    {
        return Carbon::parse(self::SINCE.' 00:00:00', CompanyTimezone::current())
            ->setTimezone((string) config('app.timezone'))
            ->toDateTimeString();
    }
}
