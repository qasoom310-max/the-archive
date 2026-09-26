{{--
    Custom 405 (method not allowed) page.

    A 405 means the address was reached the wrong way round — almost always a
    GET at an endpoint that only accepts a POST: an Import address refreshed,
    reopened from history, or bookmarked after the file went up. Nothing is
    broken and nothing was lost, but Symfony's stock page says "Something is
    broken. Please let us know", which reads to the office like the system has
    failed and leaves them nowhere to go.

    Unlike the 419 page this does NOT reload: the current address is exactly
    the one that cannot be opened this way, so reloading would loop. It offers
    the way back instead.
--}}<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('This page cannot be opened directly') }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('brand/openerp-mark.svg') }}">
    @vite(['resources/css/app.css'])
</head>
<body class="flex min-h-screen items-center justify-center bg-chrome-50 p-6">
    <div class="w-full max-w-md rounded-2xl bg-white p-6 text-center shadow-sm ring-1 ring-chrome-900/[0.06]">
        <h1 class="text-base font-semibold text-chrome-900">{{ __('This page cannot be opened directly') }}</h1>

        <p class="mt-2 text-sm text-chrome-600">
            {{ __('It is the address a form sends to, not a page to visit — reached here by a refresh, the back button or a saved link. Nothing has been lost.') }}
        </p>

        <div class="mt-5 flex flex-wrap justify-center gap-2">
            <a href="{{ url('/') }}" class="o-btn-primary text-sm">{{ __('Go to the dashboard') }}</a>
            <button type="button" onclick="history.back()"
                    class="rounded-lg border border-chrome-200 px-3 py-1.5 text-sm font-medium text-chrome-700 transition hover:bg-chrome-50">
                {{ __('Go back') }}
            </button>
        </div>
    </div>
</body>
</html>
