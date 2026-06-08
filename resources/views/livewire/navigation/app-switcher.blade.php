@php
    $tileColors = [
        'bg-primary-600', 'bg-emerald-600', 'bg-sky-600', 'bg-amber-600',
        'bg-rose-600', 'bg-indigo-600', 'bg-teal-600', 'bg-fuchsia-600',
    ];

    /**
     * Per-module icon paths. Each entry is the inner SVG markup (paths
     * only — the wrapping <svg> is rendered once in the loop). Heroicons
     * Mini (20×20, fill="currentColor"), so they inherit the tile's
     * white text colour. Modules not listed here fall through to the
     * neutral "grid" default — keeps the switcher resilient when a new
     * module ships before its icon does.
     */
    $moduleIcons = [
        'contacts' => '<path d="M10 9a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM6 8a2 2 0 1 1-4 0 2 2 0 0 1 4 0Zm-4.51 7.326a.78.78 0 0 1-.358-.442 3 3 0 0 1 4.308-3.516 6.484 6.484 0 0 0-1.905 3.959c-.023.222-.014.442.025.654a4.97 4.97 0 0 1-2.07-.655ZM12.97 16.654a4.97 4.97 0 0 0 2.07-.655.78.78 0 0 0 .357-.442 3 3 0 0 0-4.308-3.517 6.484 6.484 0 0 1 1.907 3.96 2.32 2.32 0 0 1-.026.654ZM18 8a2 2 0 1 1-4 0 2 2 0 0 1 4 0ZM5.304 16.19a.844.844 0 0 1-.277-.71 5 5 0 0 1 9.947 0 .843.843 0 0 1-.277.71A6.975 6.975 0 0 1 10 18a6.974 6.974 0 0 1-4.696-1.81Z"/>',

        'crm' => '<path fill-rule="evenodd" d="M4.25 2A2.25 2.25 0 0 0 2 4.25v2A2.25 2.25 0 0 0 4.25 8.5h11.5A2.25 2.25 0 0 0 18 6.25v-2A2.25 2.25 0 0 0 15.75 2H4.25Zm2.5 13.25a.75.75 0 0 0-1.5 0v.75a.75.75 0 0 0 1.5 0v-.75Zm0-3a.75.75 0 0 0-1.5 0v.75a.75.75 0 0 0 1.5 0v-.75Z" clip-rule="evenodd"/><path fill-rule="evenodd" d="M2 11.25v6.5A.75.75 0 0 0 2.75 18.5h6a.75.75 0 0 0 .75-.75v-2.25a.75.75 0 0 1 .75-.75h1.5a.75.75 0 0 1 .75.75v2.25c0 .414.336.75.75.75h6a.75.75 0 0 0 .75-.75v-6.5a3.488 3.488 0 0 1-2.25.815h-11.5A3.488 3.488 0 0 1 2 11.25Z" clip-rule="evenodd"/>',

        'pos' => '<path fill-rule="evenodd" d="M6 5v1H4.667a1.75 1.75 0 0 0-1.743 1.598l-.826 9.5A1.75 1.75 0 0 0 3.84 19h12.32a1.75 1.75 0 0 0 1.743-1.902l-.826-9.5A1.75 1.75 0 0 0 15.333 6H14V5a4 4 0 0 0-8 0Zm4-2.5A2.5 2.5 0 0 0 7.5 5v1h5V5A2.5 2.5 0 0 0 10 2.5ZM7.5 10a2.5 2.5 0 0 0 5 0V8.75a.75.75 0 0 1 1.5 0V10a4 4 0 0 1-8 0V8.75a.75.75 0 0 1 1.5 0V10Z" clip-rule="evenodd"/>',

        'sales' => '<path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm.75-13a.75.75 0 0 0-1.5 0v.816a3.836 3.836 0 0 0-1.72.756c-.712.566-1.112 1.35-1.112 2.178 0 .829.4 1.612 1.113 2.178.502.4 1.102.647 1.719.756v2.978a2.536 2.536 0 0 1-.921-.421l-.879-.66a.75.75 0 0 0-.9 1.2l.879.66c.533.4 1.169.645 1.821.75V15a.75.75 0 0 0 1.5 0v-.81a3.836 3.836 0 0 0 1.72-.756c.712-.566 1.112-1.35 1.112-2.178 0-.829-.4-1.612-1.113-2.178a3.836 3.836 0 0 0-1.719-.756V5.34c.305.073.591.197.847.365l.732.488a.75.75 0 0 0 .832-1.248l-.732-.488A4.036 4.036 0 0 0 10.75 5Zm-1.5 2.434c-.387.1-.728.272-.978.472-.39.31-.522.643-.522.92 0 .278.132.61.522.92.25.2.59.372.978.472v-2.78Zm1.5 5.444c.387-.1.728-.272.978-.472.39-.31.522-.643.522-.92 0-.278-.132-.61-.522-.92a2.255 2.255 0 0 0-.978-.472v2.78Z" clip-rule="evenodd"/>',

        'inventory' => '<path d="M2 3a1 1 0 0 0-1 1v1a1 1 0 0 0 1 1h16a1 1 0 0 0 1-1V4a1 1 0 0 0-1-1H2Z"/><path fill-rule="evenodd" d="M2 7.5h16l-.811 7.71a2 2 0 0 1-1.99 1.79H4.802a2 2 0 0 1-1.99-1.79L2 7.5ZM7 11a1 1 0 0 1 1-1h4a1 1 0 1 1 0 2H8a1 1 0 0 1-1-1Z" clip-rule="evenodd"/>',

        'project' => '<path fill-rule="evenodd" d="M6 3.75A2.75 2.75 0 0 1 8.75 1h2.5A2.75 2.75 0 0 1 14 3.75v.443c.572.055 1.141.122 1.706.2C17.053 4.582 18 5.75 18 7.07v3.469c0 1.126-.694 2.191-1.83 2.54-1.952.599-4.024.921-6.17.921s-4.219-.322-6.17-.921C2.694 12.73 2 11.665 2 10.539V7.07c0-1.321.947-2.489 2.294-2.676A41.047 41.047 0 0 1 6 4.193V3.75Zm6.5 0v.325a41.622 41.622 0 0 0-5 0V3.75c0-.69.56-1.25 1.25-1.25h2.5c.69 0 1.25.56 1.25 1.25ZM10 10a1 1 0 0 0-1 1v.01a1 1 0 0 0 1 1h.01a1 1 0 0 0 1-1V11a1 1 0 0 0-1-1H10Z" clip-rule="evenodd"/><path d="M3 15.055v-.684c.126.053.255.1.39.142 2.092.642 4.313.987 6.61.987 2.297 0 4.518-.345 6.61-.987.135-.041.264-.089.39-.142v.684c0 1.347-.985 2.53-2.363 2.686a41.454 41.454 0 0 1-9.274 0C3.985 17.585 3 16.402 3 15.055Z"/>',

        'settings' => '<path fill-rule="evenodd" d="M7.84 1.804A1 1 0 0 1 8.82 1h2.36a1 1 0 0 1 .98.804l.331 1.652a6.993 6.993 0 0 1 1.929 1.115l1.598-.54a1 1 0 0 1 1.186.447l1.18 2.044a1 1 0 0 1-.205 1.251l-1.267 1.113a7.047 7.047 0 0 1 0 2.228l1.267 1.113a1 1 0 0 1 .206 1.25l-1.18 2.045a1 1 0 0 1-1.187.447l-1.598-.54a6.993 6.993 0 0 1-1.929 1.115l-.33 1.652a1 1 0 0 1-.98.804H8.82a1 1 0 0 1-.98-.804l-.331-1.652a6.993 6.993 0 0 1-1.929-1.115l-1.598.54a1 1 0 0 1-1.186-.447l-1.18-2.044a1 1 0 0 1 .205-1.251l1.267-1.114a7.05 7.05 0 0 1 0-2.227L1.821 7.773a1 1 0 0 1-.206-1.25l1.18-2.045a1 1 0 0 1 1.187-.447l1.598.54A6.992 6.992 0 0 1 7.51 3.456l.33-1.652ZM10 13a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z" clip-rule="evenodd"/>',

        'accounting' => '<path fill-rule="evenodd" d="M2.5 4A1.5 1.5 0 0 0 1 5.5V6h18v-.5A1.5 1.5 0 0 0 17.5 4h-15ZM19 8.5H1v6A1.5 1.5 0 0 0 2.5 16h15a1.5 1.5 0 0 0 1.5-1.5v-6ZM3 13.25a.75.75 0 0 1 .75-.75h1.5a.75.75 0 0 1 0 1.5h-1.5a.75.75 0 0 1-.75-.75Zm4.75-.75a.75.75 0 0 0 0 1.5h3.5a.75.75 0 0 0 0-1.5h-3.5Z" clip-rule="evenodd"/>',

        'purchases' => '<path d="M1 1.75A.75.75 0 0 1 1.75 1h1.628a1.75 1.75 0 0 1 1.734 1.51L5.18 3a65.25 65.25 0 0 1 13.36 1.412.75.75 0 0 1 .58.875 48.645 48.645 0 0 1-1.618 6.2.75.75 0 0 1-.712.513H6a2.503 2.503 0 0 0-2.292 1.5H17.25a.75.75 0 0 1 0 1.5H2.76a.75.75 0 0 1-.748-.807 4.002 4.002 0 0 1 2.716-3.486L3.626 2.716a.25.25 0 0 0-.248-.216H1.75A.75.75 0 0 1 1 1.75ZM6 17.5a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0Zm9.5 1.5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z"/>',
    ];

    // Neutral fallback — 3×3 grid (same shape as the trigger button's
    // icon) so unrecognised modules still look intentional, not broken.
    $defaultModuleIcon = '<path d="M3 3h4v4H3V3Zm0 6h4v4H3V9Zm0 6h4v4H3v-4Zm6-12h4v4H9V3Zm0 6h4v4H9V9Zm0 6h4v4H9v-4Zm6-12h4v4h-4V3Zm0 6h4v4h-4V9Zm0 6h4v4h-4v-4Z"/>';
@endphp

<div x-data="{ open: false }" class="relative" @keydown.escape.window="open = false">
    <button type="button" @click="open = !open"
        class="flex size-9 items-center justify-center rounded-md text-chrome-200 hover:bg-white/10"
        title="{{ __('Apps') }}">
        <svg class="size-5" viewBox="0 0 20 20" fill="currentColor">
            <path d="M3 3h4v4H3V3Zm0 6h4v4H3V9Zm0 6h4v4H3v-4Zm6-12h4v4H9V3Zm0 6h4v4H9V9Zm0 6h4v4H9v-4Zm6-12h4v4h-4V3Zm0 6h4v4h-4V9Zm0 6h4v4h-4v-4Z"/>
        </svg>
    </button>

    {{-- `fixed` (not absolute) so the dropdown escapes every parent stacking
         context — including any subtle one created by the sidebar's
         transforms / `relative` siblings / etc. — and paints in the root
         layer at z-50, above the static sidebar that was clipping the
         tile labels on tablet. `top-12` clears the h-12 topbar; `start-2`
         lines the dropdown up with the trigger button. RTL flips both
         automatically because of the logical `start-`/`end-` utilities. --}}
    <div x-show="open" x-cloak x-transition.origin.top.left
        @click.outside="open = false"
        class="fixed start-2 top-12 z-50 mt-1 w-80 max-w-[calc(100vw-1rem)] origin-top-start rounded-xl bg-white p-3 shadow-pop ring-1 ring-chrome-900/5">
        @if ($apps->isEmpty())
            <p class="px-2 py-6 text-center text-sm text-chrome-400">
                {{ __('No applications installed.') }}<br>
                <span class="text-xs">{{ __('Run') }} <code class="rounded bg-chrome-100 px-1">php artisan module:install &lt;name&gt;</code></span>
            </p>
        @else
            <div class="grid grid-cols-3 gap-1">
                @foreach ($apps as $app)
                    @php
                        $color = $tileColors[crc32($app->name) % count($tileColors)];
                        // Prefer a registry translation by module slug so "POS" → "نقطة البيع"
                        // even if the module's display_name in `ir_module` is English. The
                        // ar.json `module.<name>` key drives this; if no entry exists the
                        // server-stored display_name is shown unchanged.
                        $moduleKey = 'module.' . $app->name;
                        $label = __($moduleKey);
                        if ($label === $moduleKey) { $label = $app->display_name; }

                        $iconBody = $moduleIcons[$app->name] ?? $defaultModuleIcon;
                    @endphp
                    <a href="{{ url('/app/' . $app->name) }}"
                        class="flex flex-col items-center gap-2 rounded-lg p-3 text-center hover:bg-chrome-100">
                        <span class="flex size-12 items-center justify-center rounded-xl {{ $color }} text-white">
                            {{-- Module-specific Heroicon body (paths only); the wrapping
                                 <svg> is the styling host. Falls back to a 3×3 grid so
                                 an unrecognised module still gets a neat tile. --}}
                            <svg class="size-6" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                {!! $iconBody !!}
                            </svg>
                        </span>
                        <span class="line-clamp-2 text-xs font-medium text-chrome-700">{{ $label }}</span>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</div>
