<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The staff app says which language the phone is in (Accept-Language); the
 * ERP answers in it — a refused sign-in or a missing driver reads in Arabic on
 * an Arabic phone. Only the two languages the ERP speaks; anything else keeps
 * the default.
 */
final class AppLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $language = strtolower(substr((string) $request->header('Accept-Language', ''), 0, 2));

        if (in_array($language, ['en', 'ar'], true)) {
            app()->setLocale($language);
        }

        return $next($request);
    }
}
