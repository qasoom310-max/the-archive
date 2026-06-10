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

        // Trust the upstream proxy that terminates TLS in front of PHP
        // (Hostinger / Cloudflare / any reverse proxy). Without this,
        // Laravel sees `http://` even though the browser is on `https://`,
        // which breaks signed-URL validation: the email's verification
        // link is signed against `https://erp.wanaan-bh.com/...` at send
        // time, but `$request->hasValidSignature()` re-derives the URL
        // from the request scheme (= `http://` once the proxy stripped
        // TLS) — different URL, different HMAC, every signed link 403s.
        // Trusting `*` means honoring `X-Forwarded-Proto: https` so the
        // re-derived URL matches what was signed. Safe in shared/managed
        // hosting where the only ingress IS that proxy.
        $middleware->trustProxies(at: '*');

        // Route each request to the active workspace's database BEFORE
        // anything reads settings/locale or checks auth. Appended (so it
        // runs after StartSession + cookie decryption) and listed before
        // SetLocale (which reads per-workspace settings). Main = no-op.
        $middleware->web(append: [
            \App\Http\Middleware\SetActiveWorkspace::class,
            \App\Http\Middleware\SetLocale::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
