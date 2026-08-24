<?php

declare(strict_types=1);

namespace App\Erp\Settings;

use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Apply the active database's configured `company.timezone`.
 *
 * Laravel's LoadConfiguration bootstrapper calls
 * `date_default_timezone_set(config('app.timezone'))` before any provider runs,
 * so BOTH the config value (read later) and PHP's default (read by Carbon) have
 * to be overridden.
 *
 * This runs twice in a tenant request: once at boot against Main, and again
 * once {@see \App\Erp\Tenancy\WorkspaceManager::activate()} has pointed the
 * connection at the workspace. Without the second pass a workspace set to a
 * different timezone silently used Main's — putting its sales on the wrong day,
 * in exactly the databases that exist to be kept separate.
 *
 * Defensive throughout: settings live in `ir_config_parameter`, which may not
 * exist during a first migrate or in CI, and a boot must never fail over a
 * setting. Anything unexpected leaves `config/app.php`'s default in place.
 */
final class CompanyTimezone
{
    public static function apply(): void
    {
        try {
            if (! Schema::hasTable('ir_config_parameter')) {
                return;
            }

            $tz = Setting::get('company.timezone');

            if (! is_string($tz) || $tz === '') {
                return;
            }

            // An invalid identifier would make every later date()/Carbon call
            // warn, so validate against PHP's own IANA list first.
            if (! in_array($tz, timezone_identifiers_list(), true)) {
                return;
            }

            config(['app.timezone' => $tz]);
            date_default_timezone_set($tz);
        } catch (Throwable) {
            // Never break a boot or a connection swap over a setting.
        }
    }

    /** The timezone currently in force, for callers that restore it later. */
    public static function current(): string
    {
        return date_default_timezone_get();
    }

    /** Put back a timezone captured with {@see current()}. */
    public static function restore(string $timezone): void
    {
        if (! in_array($timezone, timezone_identifiers_list(), true)) {
            return;
        }

        config(['app.timezone' => $timezone]);
        date_default_timezone_set($timezone);
    }
}
