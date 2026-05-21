<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Meta calls the WhatsApp webhook server-to-server with no CSRF
        // token. The route still loads inside the `web` group (only when
        // the module is installed) but must skip CSRF verification.
        $middleware->validateCsrfTokens(except: [
            'whatsapp/webhook',
        ]);

        // Drive the app locale from the `company.language` setting on
        // every web request, so `__()` and Carbon's localised output
        // pick up the admin's choice without per-controller plumbing.
        $middleware->web(append: [
            \App\Http\Middleware\SetLocale::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
