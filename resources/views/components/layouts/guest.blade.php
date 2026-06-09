<!DOCTYPE html>
@php $isRtl = in_array(app()->getLocale(), ['ar'], true); @endphp
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? __('Sign in') }} · {{ __('OpenERP') }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('brand/openerp-mark.svg') }}">
    <style>[x-cloak]{display:none!important}</style>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="flex h-full items-center justify-center bg-primary-400 font-sans">
    <div class="w-full max-w-sm px-6">
        <div class="mb-6 text-center">
            {{-- Custom company logo (set under Settings → General by an
                 admin) replaces the brand wordmark when present. The
                 fallback keeps the OpenERP / Modular ERP text so a fresh
                 install still has chrome on the login page. Logo::url()
                 returns null if no logo is uploaded OR if the file is
                 missing on disk, so a broken-image icon never renders. --}}
            @php $logoUrl = \App\Erp\Branding\Logo::url(); @endphp
            @if ($logoUrl)
                <img src="{{ $logoUrl }}" alt="{{ __('OpenERP') }}"
                    class="mx-auto mb-2 h-16 w-auto max-w-[10rem] object-contain">
            @else
                <h1 class="text-2xl font-bold tracking-tight text-chrome-900">{{ __('OpenERP') }}</h1>
                <p class="text-sm text-chrome-700">{{ __('Modular ERP') }}</p>
            @endif
        </div>
        <div class="rounded-2xl bg-white p-6 shadow-pop">
            {{ $slot }}
        </div>
    </div>
    @livewireScripts
</body>
</html>
