<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'OpenERP' }}</title>
    <style>[x-cloak]{display:none!important}</style>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="h-full font-sans">
@php
    $segments = request()->segments();
    $activeModule = ($segments[0] ?? null) === 'app' ? ($segments[1] ?? null) : null;
@endphp

<div x-data="{ collapsed: $persist(false) }" class="flex h-full flex-col">

    {{-- ───────────────────────── Top navigation bar ───────────────────────── --}}
    <header class="flex h-12 shrink-0 items-center gap-2 bg-primary-800 px-2 text-white">
        <livewire:navigation.app-switcher />

        <button type="button" @click="collapsed = !collapsed"
            class="flex size-9 items-center justify-center rounded-md text-chrome-200 hover:bg-white/10"
            title="Toggle sidebar">
            <svg class="size-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M2 4.5A1.5 1.5 0 0 1 3.5 3h13A1.5 1.5 0 0 1 18 4.5v11a1.5 1.5 0 0 1-1.5 1.5h-13A1.5 1.5 0 0 1 2 15.5v-11ZM7 4.5H4v11h3v-11Z" clip-rule="evenodd"/></svg>
        </button>

        <a href="{{ url('/') }}" class="ml-1 text-sm font-bold tracking-tight">OpenERP</a>

        {{-- Breadcrumbs --}}
        <nav class="flex items-center gap-1.5 text-sm text-chrome-300">
            <span class="text-chrome-500">/</span>
            <a href="{{ url('/') }}" class="hover:text-white">Home</a>
            @foreach ($segments as $segment)
                <span class="text-chrome-500">/</span>
                <span class="capitalize {{ $loop->last ? 'font-medium text-white' : '' }}">
                    {{ str_replace(['-', '_'], ' ', $segment) }}
                </span>
            @endforeach
        </nav>

        <div class="ml-auto flex items-center gap-1">
            {{-- Global search → command palette --}}
            <button type="button"
                @click="$dispatch('open-command-palette')"
                class="flex items-center gap-2 rounded-md bg-white/10 px-3 py-1.5 text-xs text-chrome-200 hover:bg-white/20">
                <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 1 0 3.5 9.74l3.38 3.38a1 1 0 0 0 1.42-1.42l-3.38-3.38A5.5 5.5 0 0 0 9 3.5ZM5.5 9a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0Z" clip-rule="evenodd"/></svg>
                <span class="hidden sm:inline">Search…</span>
                <kbd class="hidden rounded border border-white/20 px-1 text-[10px] sm:inline">⌘K</kbd>
            </button>

            {{-- Activities --}}
            <button type="button" class="relative flex size-9 items-center justify-center rounded-md text-chrome-200 hover:bg-white/10" title="Activities">
                <svg class="size-5" viewBox="0 0 20 20" fill="currentColor"><path d="M10 2a6 6 0 0 0-6 6v3.6l-1.3 2.6A1 1 0 0 0 3.6 16h12.8a1 1 0 0 0 .9-1.4L16 11.6V8a6 6 0 0 0-6-6Zm0 16a2.5 2.5 0 0 0 2.45-2h-4.9A2.5 2.5 0 0 0 10 18Z"/></svg>
            </button>

            {{-- User menu --}}
            @php $authUser = auth()->user(); @endphp
            <div x-data="{ open: false }" class="relative" @keydown.escape.window="open = false">
                <button type="button" @click="open = !open"
                    class="flex items-center gap-2 rounded-md py-1 pl-1 pr-2 hover:bg-white/10">
                    <span class="flex size-7 items-center justify-center rounded-full bg-white/20 text-xs font-semibold">
                        {{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($authUser?->name ?? '?', 0, 1)) }}
                    </span>
                    <span class="hidden text-sm sm:inline">{{ $authUser?->name ?? 'Guest' }}</span>
                </button>
                <div x-show="open" x-cloak x-transition.origin.top.right @click.outside="open = false"
                    class="absolute right-0 z-40 mt-2 w-52 rounded-lg bg-white py-1 text-chrome-700 shadow-pop ring-1 ring-chrome-900/5">
                    <div class="border-b border-chrome-100 px-3 py-2 text-xs text-chrome-400">
                        Signed in as<br>
                        <span class="font-medium text-chrome-700">{{ $authUser?->name }}</span>
                        <span class="block text-chrome-400">{{ $authUser?->email }}</span>
                    </div>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="block w-full px-3 py-2 text-left text-sm text-red-600 hover:bg-chrome-100">
                            Sign out
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </header>

    {{-- ───────────────────────── Body: sidebar + content ──────────────────── --}}
    <div class="flex min-h-0 flex-1">
        <aside :class="collapsed ? 'w-14' : 'w-60'"
            class="shrink-0 overflow-y-auto border-r border-chrome-200 bg-chrome-50 transition-[width] duration-150">
            <livewire:navigation.sidebar :active-module="$activeModule" />
        </aside>

        <main class="min-w-0 flex-1 overflow-y-auto">
            {{ $slot }}
        </main>
    </div>

    {{-- Global command palette (⌘K) --}}
    <livewire:navigation.command-palette />

    {{-- Global save toast. Reads the session 'toast' flash set by FormView::save
         (so it survives a wire:navigate redirect, including a redirect-to-same-URL
         like POS product edit) and also listens to the engine's `record-saved`
         browser event for forms that don't redirect. Auto-dismisses after 2.5s.
         Notes: `@js()` is attribute-safe (escapes quotes as &quot;) — `@json()`
         is NOT and breaks `x-data="…"` when the value contains a string.
         `x-on:` longhand avoids any chance of Blade mis-parsing `@record-saved`. --}}
    @php
        $hasFlash = session()->has('toast');
        $flashMsg = (string) session('toast', 'Saved.');
    @endphp
    <div x-data="{ show: {{ $hasFlash ? 'true' : 'false' }}, msg: @js($flashMsg), flash(t) { this.msg = t; this.show = true; setTimeout(() => this.show = false, 2500); } }"
         x-init="if (show) setTimeout(() => show = false, 2500)"
         x-on:record-saved.window="flash('Saved.')"
         x-show="show" x-transition x-cloak
         class="pointer-events-none fixed right-4 top-16 z-50 flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white shadow-pop"
         role="status" aria-live="polite">
        <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 0 1 0 1.4l-8 8a1 1 0 0 1-1.4 0l-4-4a1 1 0 1 1 1.4-1.4L8 12.6l7.3-7.3a1 1 0 0 1 1.4 0Z" clip-rule="evenodd"/></svg>
        <span x-text="msg"></span>
    </div>
</div>

@livewireScripts
</body>
</html>
