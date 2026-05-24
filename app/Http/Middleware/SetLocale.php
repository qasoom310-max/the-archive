<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Erp\Settings\Setting;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Drive the request locale from the **logged-in user's** `language`
 * preference, falling back to the system-wide `company.language`
 * setting (= the default for guests / new users without a personal
 * choice). Read happens once per request before controllers/Livewire
 * run, so `__()` returns the right strings everywhere.
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
        // Per-user preference first (so Faraj on `ar` and Qassim on
        // `en` don't trample each other), then the system default for
        // the login page / un-set users.
        $user = Auth::user();
        $code = $user instanceof User && $user->language !== null && $user->language !== ''
            ? $user->language
            : (string) Setting::get('company.language', 'en');

        $code = strtolower($code);

        if (! in_array($code, self::SUPPORTED, true)) {
            $code = 'en';
        }

        app()->setLocale($code);

        return $next($request);
    }
}
