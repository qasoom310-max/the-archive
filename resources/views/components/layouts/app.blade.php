<!DOCTYPE html>
@php
    // Direction flips on locale: Arabic (and any other RTL locale we add
    // later) renders sidebar-on-the-right, right-aligned text, and
    // logical-property utilities (ms-/me-/ps-/pe-/start-/end-) reflect
    // the way the user reads. The middleware (SetLocale) has already
    // pushed the right code into app()->getLocale() by this point.
    $isRtl = in_array(app()->getLocale(), ['ar'], true);
    // Per-user appearance preference (light|dark|system). null / missing
    // column (pre-migration) falls back to following the OS.
    $themePref = (auth()->user()?->theme ?? null) ?: 'system';
@endphp
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}" data-theme="{{ $themePref }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'OpenERP' }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('brand/openerp-mark.svg') }}">
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
    {{-- Dark mode: apply the theme class BEFORE the stylesheet loads so there's
         no light-mode flash. `applyTheme` is exposed globally so the settings
         page can re-apply it live (via the `theme-changed` event) without a
         page reload, and OS changes are tracked while in "system" mode. --}}
    <script>
        (function () {
            window.applyTheme = function (pref) {
                var mql = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)');
                var dark = pref === 'dark' || (pref !== 'light' && mql && mql.matches);
                document.documentElement.classList.toggle('dark', dark);
                document.documentElement.dataset.themePref = pref;
            };
            window.applyTheme(document.documentElement.getAttribute('data-theme') || 'system');
            if (window.matchMedia) {
                window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function () {
                    if ((document.documentElement.dataset.themePref || 'system') === 'system') {
                        window.applyTheme('system');
                    }
                });
            }
        })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="h-full font-sans"
    x-data
    x-on:language-changed.window="window.location.reload()"
    x-on:theme-changed.window="window.applyTheme($event.detail.value)">
@php
    $segments = request()->segments();
    $activeModule = ($segments[0] ?? null) === 'app' ? ($segments[1] ?? null) : null;
@endphp

<div class="flex h-full flex-col">

    {{-- ───────────────────────── Top navigation bar ───────────────────────── --}}
    {{-- Brand chrome is the bright #F5EF1A; white text is unreadable on it, so
         everything in the bar uses dark (chrome-900/800) text and black/N
         translucent hover overlays instead of white/N. --}}
    <header class="flex h-12 shrink-0 items-center gap-1 bg-primary-400 px-2 text-chrome-900 sm:gap-2">
        {{-- Brand: custom company logo when an admin has uploaded one,
             else the OpenERP wordmark. Logo::url() is null-safe (missing
             file → null → fallback to text). --}}
        @php $brandLogoUrl = \App\Erp\Branding\Logo::url(); @endphp
        <a href="{{ url('/') }}" wire:navigate class="ms-1 flex items-center" aria-label="{{ __('OpenERP') }}" title="{{ __('Home') }}">
            @if ($brandLogoUrl)
                <img src="{{ $brandLogoUrl }}" alt="{{ __('OpenERP') }}" class="h-7 w-auto max-w-[8rem] object-contain">
            @else
                <span class="text-sm font-bold tracking-tight">{{ __('OpenERP') }}</span>
            @endif
        </a>

        {{-- Always-visible app bar (replaced the 9-square dropdown). Renders
             every installed application as an inline link; the flexible
             middle region of the topbar, scrolls horizontally on overflow.
             Highlights the current module via $activeModule. --}}
        <livewire:navigation.app-switcher :active-module="$activeModule" />

        {{-- Breadcrumbs moved out of the topbar to their own strip directly
             below it (see the breadcrumb bar after </header>). --}}

        <div class="ms-auto flex items-center gap-1">
            {{-- Global search → command palette --}}
            <button type="button"
                @click="$dispatch('open-command-palette')"
                class="flex items-center gap-2 rounded-md bg-black/10 px-3 py-1.5 text-xs text-chrome-800 hover:bg-black/20">
                <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 1 0 3.5 9.74l3.38 3.38a1 1 0 0 0 1.42-1.42l-3.38-3.38A5.5 5.5 0 0 0 9 3.5ZM5.5 9a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0Z" clip-rule="evenodd"/></svg>
                <span class="hidden sm:inline">{{ __('Search…') }}</span>
                <kbd class="hidden rounded border border-black/20 px-1 text-[10px] sm:inline">⌘K</kbd>
            </button>

            {{-- Notification bell — cross-module alerts via the NotificationCenter. --}}
            <livewire:navigation.notification-bell />

            {{-- Activity log — admin-only audit trail of everything users do. --}}
            @if (auth()->user()?->isAdmin())
                <a href="{{ route('activity') }}" wire:navigate
                    class="relative flex size-9 items-center justify-center rounded-md text-chrome-800 hover:bg-black/10"
                    title="{{ __('Activity log') }}" aria-label="{{ __('Activity log') }}">
                    {{-- Heroicons outline clipboard-document-list --}}
                    <svg class="size-5" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6.75M9 15.75h6.75M9 8.25h6.75M5.25 6.75h.008v.008H5.25V6.75Zm0 3.75h.008v.008H5.25V10.5Zm0 3.75h.008v.008H5.25v-.008Z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 6.75h.008M9 6.75H5.25A1.5 1.5 0 0 0 3.75 8.25v10.5a1.5 1.5 0 0 0 1.5 1.5h13.5a1.5 1.5 0 0 0 1.5-1.5V8.25a1.5 1.5 0 0 0-1.5-1.5H9Z" />
                    </svg>
                </a>
            @endif

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
                    class="flex items-center gap-2 rounded-md py-1 pl-1 pr-2 hover:bg-black/10">
                    <span class="flex size-7 items-center justify-center overflow-hidden rounded-full bg-black/15 text-xs font-semibold">
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
                    {{-- My database (multi-tenant) — admin-only, and hidden for a
                         user locked to a single workspace (they can't switch or
                         manage databases). Opens the database manager. --}}
                    @if ($authUser?->isAdmin() && ! $authUser->isLockedToWorkspace())
                        <a href="{{ url('/workspaces') }}" wire:navigate
                            class="flex items-center gap-2 px-3 py-2 text-sm hover:bg-chrome-100">
                            {{-- Heroicons mini circle-stack (database) --}}
                            <svg class="size-4 text-chrome-500" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 1c-3.866 0-7 1.343-7 3v12c0 1.657 3.134 3 7 3s7-1.343 7-3V4c0-1.657-3.134-3-7-3Zm5 15c0 .35-1.793 1.5-5 1.5S5 16.35 5 16v-2.05c1.298.66 3.107 1.05 5 1.05s3.702-.39 5-1.05V16Zm0-4c0 .35-1.793 1.5-5 1.5S5 12.35 5 12V9.95C6.298 10.61 8.107 11 10 11s3.702-.39 5-1.05V12Zm-5-3C6.793 9 5 7.85 5 7.5V5.95C6.298 6.61 8.107 7 10 7s3.702-.39 5-1.05V7.5c0 .35-1.793 1.5-5 1.5Z"/></svg>
                            {{ __('My database') }}
                        </a>
                    @endif
                    {{-- Settings lives here (not the sidebar). Shown to every
                         authenticated user; SettingsPage enforces what each
                         role may actually edit (admins all, others language). --}}
                    <a href="{{ url('/app/settings') }}" wire:navigate
                        class="flex items-center gap-2 px-3 py-2 text-sm hover:bg-chrome-100">
                        {{-- Heroicons outline cog-6-tooth --}}
                        <svg class="size-4 text-chrome-500" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.24-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                        </svg>
                        {{ __('Settings') }}
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

    {{-- ───────────────────────── Breadcrumb bar ──────────────────────────
         Its own full-width strip directly under the topbar (moved out of the
         yellow topbar so the always-visible app bar owns that row). Light
         chrome strip with muted, dark breadcrumb text. A page can override
         the LAST segment's label via the `breadcrumb_terminal_label` request
         attribute set in its render() — generic, no model coupling here.
         Hidden on phones (md+) so it doesn't eat vertical space on small
         screens. --}}
    @php
        $terminalOverride = request()->attributes->get('breadcrumb_terminal_label');
        $terminalLabel = is_string($terminalOverride) && $terminalOverride !== '' ? $terminalOverride : null;
    @endphp
    <nav class="hidden shrink-0 items-center gap-1.5 border-b border-chrome-200 bg-white px-4 py-2 text-sm text-chrome-500 md:flex"
        aria-label="{{ __('Breadcrumb') }}">
        {{-- Inside an app (/app/<module>/…) the root crumb is that module's home
             (icon + name), NOT the global dashboard — pressing it keeps the user
             in the module. The company logo (topbar) is the only way back to the
             main dashboard. Outside a module we fall back to a plain "Home". --}}
        @if ($activeModule !== null)
            @php
                $modKey = 'module.' . $activeModule;
                $modLabel = __($modKey);
                if ($modLabel === $modKey) {
                    $modLabel = \App\Models\Ir\IrModule::query()->where('name', $activeModule)->value('display_name')
                        ?: \Illuminate\Support\Str::headline($activeModule);
                }
                $modIcon = \App\Erp\Navigation\ModuleIcon::body($activeModule);
                // Crumbs after /app/<module> (the module home owns the first two).
                $rest = array_slice($segments, 2);
            @endphp
            <a href="{{ url('/app/' . $activeModule) }}" wire:navigate class="flex items-center gap-1.5 hover:text-chrome-800">
                <svg class="size-4 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">{!! $modIcon !!}</svg>
                <span>{{ $modLabel }}</span>
            </a>
            @php $cumulative = ['app', $activeModule]; @endphp
            @foreach ($rest as $segment)
                @php
                    $cumulative[] = $segment;
                    $isLast = $loop->last;
                    $label = $isLast && $terminalLabel !== null
                        ? $terminalLabel
                        : __(str_replace(['-', '_'], ' ', $segment));
                @endphp
                <span class="text-chrome-300">/</span>
                @if ($isLast)
                    <span class="{{ $terminalLabel === null ? 'capitalize' : '' }} font-medium text-chrome-800">{{ $label }}</span>
                @else
                    <a href="{{ url('/' . implode('/', $cumulative)) }}" wire:navigate class="capitalize hover:text-chrome-800">{{ $label }}</a>
                @endif
            @endforeach
        @else
            <a href="{{ url('/') }}" wire:navigate class="hover:text-chrome-800">{{ __('Home') }}</a>
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
                <span class="text-chrome-300">/</span>
                @if ($isLast || $isAppPrefix)
                    <span class="{{ $isLast && $terminalLabel === null ? 'capitalize' : '' }} {{ $isLast ? 'font-medium text-chrome-800' : '' }}">
                        {{ $label }}
                    </span>
                @else
                    <a href="{{ url('/' . implode('/', $cumulative)) }}" wire:navigate
                        class="capitalize hover:text-chrome-800">
                        {{ $label }}
                    </a>
                @endif
            @endforeach
        @endif
    </nav>

    {{-- ───────────────────────────── Body: content ──────────────────────────
         The contextual sidebar was removed (2026-06-10). Each app lands on its
         own dashboard of tiles (the engine ModuleHome, or each module's home),
         so the app's models are reachable from there; app-to-app switching is
         the always-visible topbar app bar. The MAIN dashboard (daily report,
         KPIs) is reached via the brand logo or the breadcrumb "Home" link. --}}
    <main class="min-h-0 flex-1 overflow-y-auto">
        {{ $slot }}
    </main>

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
