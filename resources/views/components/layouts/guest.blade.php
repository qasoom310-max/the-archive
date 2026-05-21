<!DOCTYPE html>
@php $isRtl = in_array(app()->getLocale(), ['ar'], true); @endphp
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? __('Sign in') }} · {{ __('OpenERP') }}</title>
    <style>[x-cloak]{display:none!important}</style>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="flex h-full items-center justify-center bg-primary-800 font-sans">
    <div class="w-full max-w-sm px-6">
        <div class="mb-6 text-center">
            <h1 class="text-2xl font-bold tracking-tight text-white">{{ __('OpenERP') }}</h1>
            <p class="text-sm text-primary-200">{{ __('Modular ERP') }}</p>
        </div>
        <div class="rounded-2xl bg-white p-6 shadow-pop">
            {{ $slot }}
        </div>
    </div>
    @livewireScripts
</body>
</html>
