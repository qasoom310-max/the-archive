<!DOCTYPE html>
@php
    $isRtl = in_array(app()->getLocale(), ['ar'], true);
    // Native date pickers render in the LANGUAGE TAG's format, and a bare
    // "en" means American. Bahrain writes day/month/year, so English is
    // served as en-GB — same as the app layout.
    $lang = str_replace('_', '-', app()->getLocale());
    $lang = $lang === 'en' ? 'en-GB' : $lang;
@endphp
<html lang="{{ $lang }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? __('Sign in') }} · {{ __('OpenERP') }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('brand/openerp-mark.svg') }}">
    <style>[x-cloak]{display:none!important}</style>
    {{-- Forget the idle-sign-out clock (see layouts/app.blade.php) so the next
         sign-in starts fresh instead of inheriting a stale timestamp. --}}
    <script>try { window.localStorage.removeItem('erp.lastActivity'); } catch (e) {}</script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="flex h-full items-center justify-center bg-primary-400 font-sans">
    <div class="w-full max-w-sm px-6">
        {{-- Big "ERP" wordmark. A bold, tightly-tracked mark on the brand
             yellow reads cleaner than a boxed logo on the login screen. --}}
        <div class="mb-6 text-center">
            <h1 class="text-7xl font-black leading-none tracking-tight text-chrome-900 drop-shadow-sm">ERP</h1>
            <p class="mt-2 text-xs font-semibold uppercase tracking-[0.35em] text-chrome-800/60">{{ __('Modular ERP') }}</p>
        </div>
        <div class="rounded-2xl bg-white p-6 shadow-pop">
            {{ $slot }}
        </div>
    </div>
    @livewireScripts
</body>
</html>
