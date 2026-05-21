<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Erp\Settings\Setting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Drive the request locale from the `company.language` setting so a
 * single admin-side toggle re-skins the whole app in Arabic (or any
 * future locale we add to the registry). Read happens once per
 * request before controllers/Livewire run, so `__()` returns the
 * right strings everywhere.
 *
 * The list of allowed codes is whitelisted to defend against an
 * out-of-range value parking an unsupported locale — anything that
 * isn't EN or AR silently falls back to EN.
 */
final class SetLocale
{
    private const SUPPORTED = ['en', 'ar'];

    public function handle(Request $request, Closure $next): Response
    {
        $code = strtolower((string) Setting::get('company.language', 'en'));

        if (! in_array($code, self::SUPPORTED, true)) {
            $code = 'en';
        }

        app()->setLocale($code);

        return $next($request);
    }
}
