<!DOCTYPE html>
@php
    // Direction flips on locale: Arabic (and any other RTL locale we add
    // later) renders sidebar-on-the-right, right-aligned text, and
    // logical-property utilities (ms-/me-/ps-/pe-/start-/end-) reflect
    // the way the user reads. The middleware (SetLocale) has already
    // pushed the right code into app()->getLocale() by this point.
    $isRtl = in_array(app()->getLocale(), ['ar'], true);
@endphp
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'OpenERP' }}</title>
    <style>[x-cloak]{display:none!important}</style>
    {{-- Silent reload on CSRF token mismatch — installed BEFORE any other
         script so Livewire's request loop (which calls `confirm(…)` for
         419 via a global bareword lookup) hits our override. Touching any
         other confirm() call is left untouched. --}}
    <script>
        (function () {
            const originalConfirm = window.confirm.bind(window);
            window.confirm = function (message) {
                if (typeof message === 'string' && message.indexOf('This page has expired') !== -1) {
                    window.location.reload();
                    return false;
                }
                return originalConfirm(message);
            };
        })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="h-full font-sans"
    x-data
    x-on:language-changed.window="window.location.reload()">
@php
    $segments = request()->segments();
    $activeModule = ($segments[0] ?? null) === 'app' ? ($segments[1] ?? null) : null;
@endphp

<div x-data="{ collapsed: $persist(false), mobileOpen: false }" class="flex h-full flex-col"
    @keydown.escape.window="mobileOpen = false">

    {{-- ───────────────────────── Top navigation bar ───────────────────────── --}}
    <header class="flex h-12 shrink-0 items-center gap-1 bg-primary-800 px-2 text-white sm:gap-2">
        <livewire:navigation.app-switcher />

        {{-- Sidebar toggle. On desktop (`md+`) this collapses the always-visible
             aside between w-60 and w-14. On mobile it opens an overlay drawer
             (the aside element below switches mode at the same breakpoint). --}}
        <button type="button"
            @click="window.innerWidth >= 768 ? collapsed = !collapsed : mobileOpen = !mobileOpen"
            class="flex size-9 items-center justify-center rounded-md text-chrome-200 hover:bg-white/10"
            title="{{ __('Toggle sidebar') }}">
            <svg class="size-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M2 4.5A1.5 1.5 0 0 1 3.5 3h13A1.5 1.5 0 0 1 18 4.5v11a1.5 1.5 0 0 1-1.5 1.5h-13A1.5 1.5 0 0 1 2 15.5v-11ZM7 4.5H4v11h3v-11Z" clip-rule="evenodd"/></svg>
        </button>

        {{-- Brand: custom company logo when an admin has uploaded one,
             else the OpenERP wordmark. Logo::url() is null-safe (missing
             file → null → fallback to text). --}}
        @php $brandLogoUrl = \App\Erp\Branding\Logo::url(); @endphp
        <a href="{{ url('/') }}" class="ms-1 flex items-center" aria-label="{{ __('OpenERP') }}">
            @if ($brandLogoUrl)
                <img src="{{ $brandLogoUrl }}" alt="{{ __('OpenERP') }}" class="h-7 w-auto max-w-[8rem] object-contain">
            @else
                <span class="text-sm font-bold tracking-tight">{{ __('OpenERP') }}</span>
            @endif
        </a>

        {{-- Breadcrumbs — every segment is a link back to its cumulative
             URL except the LAST one (the page you're on) and the bare
             `app` prefix (no route registered at /app on its own). Skip-
             linking the terminal segment keeps the current page visually
             distinct (white + medium weight); skip-linking `app` avoids
             dropping the user on a 404.

             A page can replace the LAST segment's display text (e.g. "96"
             → "بيبسي دايت") by setting the `breadcrumb_terminal_label`
             request attribute inside its `render()` — generic mechanism,
             no model coupling in the layout. --}}
        @php
            $terminalOverride = request()->attributes->get('breadcrumb_terminal_label');
            $terminalLabel = is_string($terminalOverride) && $terminalOverride !== '' ? $terminalOverride : null;
        @endphp
        {{-- Breadcrumbs eat horizontal space the topbar can't afford on a
             360px phone — the brand + hamburger + search button + user menu
             already push the layout to the edge. Show from `md` upward. --}}
        <nav class="hidden items-center gap-1.5 text-sm text-chrome-300 md:flex">
            <span class="text-chrome-500">/</span>
            <a href="{{ url('/') }}" wire:navigate class="hover:text-white">{{ __('Home') }}</a>
            @php $cumulative = []; @endphp
            @foreach ($segments as $segment)
                @php
                    $cumulative[] = $segment;
                    $isLast = $loop->last;
                    $isAppPrefix = $loop->first && $segment === 'app';
                    $label = $isLast && $terminalLabel !== null
                        ? $terminalLabel
                        : __(str_replace(['-', '_'], ' ', $segment));
                @endphp
                <span class="text-chrome-500">/</span>
                @if ($isLast || $isAppPrefix)
                    <span class="{{ $isLast && $terminalLabel === null ? 'capitalize' : '' }} {{ $isLast ? 'font-medium text-white' : '' }}">
                        {{ $label }}
                    </span>
                @else
                    <a href="{{ url('/' . implode('/', $cumulative)) }}" wire:navigate
                        class="capitalize hover:text-white">
                        {{ $label }}
                    </a>
                @endif
            @endforeach
        </nav>

        <div class="ms-auto flex items-center gap-1">
            {{-- Global search → command palette --}}
            <button type="button"
                @click="$dispatch('open-command-palette')"
                class="flex items-center gap-2 rounded-md bg-white/10 px-3 py-1.5 text-xs text-chrome-200 hover:bg-white/20">
                <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 1 0 3.5 9.74l3.38 3.38a1 1 0 0 0 1.42-1.42l-3.38-3.38A5.5 5.5 0 0 0 9 3.5ZM5.5 9a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0Z" clip-rule="evenodd"/></svg>
                <span class="hidden sm:inline">{{ __('Search…') }}</span>
                <kbd class="hidden rounded border border-white/20 px-1 text-[10px] sm:inline">⌘K</kbd>
            </button>

            {{-- Activities --}}
            <button type="button" class="relative flex size-9 items-center justify-center rounded-md text-chrome-200 hover:bg-white/10" title="{{ __('Activities') }}">
                <svg class="size-5" viewBox="0 0 20 20" fill="currentColor"><path d="M10 2a6 6 0 0 0-6 6v3.6l-1.3 2.6A1 1 0 0 0 3.6 16h12.8a1 1 0 0 0 .9-1.4L16 11.6V8a6 6 0 0 0-6-6Zm0 16a2.5 2.5 0 0 0 2.45-2h-4.9A2.5 2.5 0 0 0 10 18Z"/></svg>
            </button>

            {{-- User menu — avatar URL goes through User::avatarUrl() so
                 a stale `avatar_path` pointing at a missing file falls
                 back to null (initial letter) instead of a broken-image
                 icon. Same guard runs on the profile page. --}}
            @php
                $authUser = auth()->user();
                $authAvatarUrl = $authUser?->avatarUrl();
            @endphp
            <div x-data="{ open: false }" class="relative" @keydown.escape.window="open = false">
                <button type="button" @click="open = !open"
                    class="flex items-center gap-2 rounded-md py-1 pl-1 pr-2 hover:bg-white/10">
                    <span class="flex size-7 items-center justify-center overflow-hidden rounded-full bg-white/20 text-xs font-semibold">
                        @if ($authAvatarUrl)
                            <img src="{{ $authAvatarUrl }}" alt="{{ $authUser?->name }}" class="size-full object-cover">
                        @else
                            {{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($authUser?->name ?? '?', 0, 1)) }}
                        @endif
                    </span>
                    <span class="hidden text-sm sm:inline">{{ $authUser?->name ?? __('Guest') }}</span>
                </button>
                <div x-show="open" x-cloak x-transition.origin.top.right @click.outside="open = false"
                    class="absolute end-0 z-40 mt-2 w-52 rounded-lg bg-white py-1 text-chrome-700 shadow-pop ring-1 ring-chrome-900/5">
                    <div class="border-b border-chrome-100 px-3 py-2 text-xs text-chrome-400">
                        {{ __('Signed in as') }}<br>
                        <span class="font-medium text-chrome-700">{{ $authUser?->name }}</span>
                        <span class="block text-chrome-400">{{ $authUser?->email }}</span>
                    </div>
                    <a href="{{ route('profile') }}" wire:navigate
                        class="flex items-center gap-2 px-3 py-2 text-sm hover:bg-chrome-100">
                        {{-- Heroicons mini user-circle --}}
                        <svg class="size-4 text-chrome-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M17.982 18.725A7.488 7.488 0 0 0 12 15.75a7.488 7.488 0 0 0-5.982 2.975m11.963 0a9 9 0 1 0-11.963 0m11.963 0A8.966 8.966 0 0 1 12 21a8.966 8.966 0 0 1-5.982-2.275M15 9.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                        </svg>
                        {{ __('Profile') }}
                    </a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="block w-full px-3 py-2 text-start text-sm text-red-600 hover:bg-chrome-100">
                            {{ __('Sign out') }}
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </header>

    {{-- ───────────────────────── Body: sidebar + content ────────────────────
         No `relative` here — that would establish a new stacking context that
         sits ABOVE the header (sibling, also auto-z, but later in DOM) and
         shove the sidebar's static column on top of any header dropdown
         (app-switcher, user menu) that extends down past the topbar.
         The backdrop and drawer below are `fixed`, so they position against
         the viewport directly and don't need a relative parent. --}}
    <div class="flex min-h-0 flex-1">
        {{-- Backdrop for the mobile drawer. Only renders on small screens
             (`md:hidden`); clicking it dismisses the drawer. --}}
        <div x-show="mobileOpen" x-cloak x-transition.opacity
            @click="mobileOpen = false"
            class="fixed inset-0 z-30 bg-chrome-900/40 md:hidden"
            aria-hidden="true"></div>

        {{-- Sidebar. Two layout modes driven by viewport size:
             - `md+`: an always-visible column in the flex flow, width
               toggled by the `collapsed` persist flag.
             - `<md`: a fixed-position overlay drawer that slides in from
               the start edge when `mobileOpen` is true; otherwise pushed
               off-screen via a negative translate. The `start-0` anchor
               flips automatically for RTL. --}}
        {{-- Translate-x classes are scoped to `max-md:` so they apply only on
             phones. Any CSS `transform` — even translate(0) — creates a
             stacking context; on tablet+ that context competed with the
             header's app-switcher dropdown at the same z-level, and DOM
             order made the static sidebar render over the dropdown labels.
             Restricting transforms to mobile leaves the desktop sidebar
             in pure document flow, so header dropdowns paint cleanly above. --}}
        <aside
            :class="[
                collapsed ? 'md:w-14' : 'md:w-60',
                mobileOpen ? 'max-md:translate-x-0' : 'max-md:-translate-x-full max-md:rtl:translate-x-full'
            ]"
            class="fixed inset-y-0 start-0 top-12 z-40 w-64 shrink-0 overflow-y-auto border-e border-chrome-200 bg-chrome-50 transition-transform duration-150 md:static md:top-0 md:transition-[width]">
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
        $flashMsg = (string) session('toast', __('Saved.'));
        $savedLabel = __('Saved.');
    @endphp
    <div x-data="{ show: {{ $hasFlash ? 'true' : 'false' }}, msg: @js($flashMsg), flash(t) { this.msg = t; this.show = true; setTimeout(() => this.show = false, 2500); } }"
         x-init="if (show) setTimeout(() => show = false, 2500)"
         x-on:record-saved.window="flash(@js($savedLabel))"
         x-show="show" x-transition x-cloak
         class="pointer-events-none fixed end-4 top-16 z-50 flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white shadow-pop"
         role="status" aria-live="polite">
        <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 0 1 0 1.4l-8 8a1 1 0 0 1-1.4 0l-4-4a1 1 0 1 1 1.4-1.4L8 12.6l7.3-7.3a1 1 0 0 1 1.4 0Z" clip-rule="evenodd"/></svg>
        <span x-text="msg"></span>
    </div>
</div>

@livewireScripts

</body>
</html>
