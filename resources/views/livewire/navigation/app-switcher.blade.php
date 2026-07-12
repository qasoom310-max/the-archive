{{-- Always-visible app bar. Flexible middle region of the topbar: it
     takes the space left between the brand and the right-hand controls
     and scrolls horizontally if more apps are installed than fit (so the
     bar never wraps and breaks the fixed-height header). The active app
     is highlighted.

     Each app whose module registers any readable model is a DROPDOWN of
     those models (the same entries as its app-home tile dashboard, via
     ModuleMenu) — so any list is one hop from the topbar, for every app.
     Apps with no models (Inventory, Settings, …) stay a plain home link.

     Each app is its OWN isolated Alpine scope (`open`) — the proven orders
     3-dot-menu pattern — so panels can't cross-talk or stack. Each panel is
     a DOM child of its trigger's root (so `@click.outside` closes it and the
     button click can't self-close) but positioned `fixed` at viewport coords
     captured on open, so it escapes the bar's `overflow-x-auto` clip (no
     transformed ancestor exists to trap a fixed element). --}}
<nav class="flex min-w-0 flex-1 items-center gap-0.5 overflow-x-auto"
    aria-label="{{ __('Applications') }}">
    @foreach ($apps as $app)
        @php
            // Prefer a registry translation by module slug so "POS" → "نقطة البيع"
            // even if the module's display_name in `ir_module` is English. The
            // ar.json `module.<name>` key drives this; with no entry the
            // server-stored display_name is shown unchanged.
            $moduleKey = 'module.' . $app->name;
            $label = __($moduleKey);
            if ($label === $moduleKey) { $label = $app->display_name; }

            $iconBody = \App\Erp\Navigation\ModuleIcon::body($app->name);
            $isActive = $activeModule === $app->name;
            $items = $menus[$app->name] ?? [];
            $settingsUrl = $settingsUrls[$app->name] ?? null;
            $homeUrl = url('/app/' . $app->name);
        @endphp

        @if (count($items) > 0 || $settingsUrl)
            {{-- App with a model menu → isolated dropdown scope. --}}
            <div x-data="{ open: false, coords: {} }"
                @click.outside="open = false"
                @keydown.escape.window="open = false"
                class="relative shrink-0">
                <button type="button"
                    @click="
                        open = ! open;
                        if (open) {
                            const r = $event.currentTarget.getBoundingClientRect();
                            const rtl = document.documentElement.getAttribute('dir') === 'rtl';
                            // Clamp the anchor so a right-most (or, in RTL, left-most)
                            // trigger can't push the ~14rem panel off-screen and force
                            // horizontal page scroll on a phone.
                            const margin = 8, panelW = 224, maxOff = window.innerWidth - panelW - margin;
                            const left = Math.max(margin, Math.min(r.left, maxOff));
                            const right = Math.max(margin, Math.min(window.innerWidth - r.right, maxOff));
                            coords = { top: r.bottom + 4, left, right, rtl };
                        }
                    "
                    @class([
                        'flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-sm whitespace-nowrap transition-colors',
                        'bg-black/10 font-medium text-chrome-900' => $isActive,
                        'text-chrome-800 hover:bg-black/10 hover:text-chrome-900' => ! $isActive,
                    ])
                    :aria-expanded="open"
                    aria-haspopup="true"
                    title="{{ $label }}">
                    <svg class="size-4 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        {!! $iconBody !!}
                    </svg>
                    <span class="hidden sm:inline">{{ $label }}</span>
                    <svg class="size-3.5 shrink-0 transition-transform" :class="open && 'rotate-180'"
                        viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd"/>
                    </svg>
                </button>

                {{-- `fixed` (not absolute) so the panel anchors to the viewport
                     and escapes the bar's `overflow-x-auto` clip. Stays a DOM
                     child of the root so @click.outside works. --}}
                <div x-show="open" x-cloak x-transition.opacity.duration.100ms
                    :style="`top:${coords.top}px; ${coords.rtl ? 'right:' + coords.right + 'px' : 'left:' + coords.left + 'px'}`"
                    class="fixed z-50 max-h-[70vh] min-w-[13rem] max-w-[calc(100vw-1rem)] overflow-y-auto rounded-lg border border-chrome-200 bg-white py-1.5 shadow-pop">
                    {{-- Open the app's home dashboard. --}}
                    <a href="{{ $homeUrl }}"
                        class="flex items-center gap-2 px-3 py-1.5 text-sm font-semibold text-chrome-900 hover:bg-chrome-100">
                        <svg class="size-4 shrink-0 text-primary-600" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            {!! $iconBody !!}
                        </svg>
                        {{ $label }}
                    </a>
                    <div class="my-1 border-t border-chrome-100"></div>
                    @foreach ($items as $item)
                        <a href="{{ $item['url'] }}"
                            class="flex items-center justify-between gap-3 px-3 py-1.5 text-sm text-chrome-700 hover:bg-chrome-100">
                            <span>{{ __($item['label']) }}</span>
                            <svg class="size-3.5 shrink-0 text-chrome-300 rtl:rotate-180" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.168 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd"/>
                            </svg>
                        </a>
                    @endforeach

                    {{-- Production & store — a bespoke POS screen (not a model), so
                         it isn't in the model menu. Shown when the feature is on. --}}
                    @if ($app->name === 'pos' && \App\Erp\Business\Features::enabled(\App\Erp\Business\Feature::Production))
                        <a href="{{ url('/app/pos/production') }}"
                            class="flex items-center justify-between gap-3 px-3 py-1.5 text-sm text-chrome-700 hover:bg-chrome-100">
                            <span>{{ __('Production & store') }}</span>
                            <svg class="size-3.5 shrink-0 text-chrome-300 rtl:rotate-180" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.168 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd"/>
                            </svg>
                        </a>
                    @endif

                    {{-- Remote / delivery sales — the fulfillment queue for
                         phone / WhatsApp / delivery orders. Bespoke screen. --}}
                    @if ($app->name === 'pos' && \App\Erp\Business\Features::enabled(\App\Erp\Business\Feature::RemoteSales))
                        <a href="{{ url('/app/pos/remote') }}"
                            class="flex items-center justify-between gap-3 px-3 py-1.5 text-sm text-chrome-700 hover:bg-chrome-100">
                            <span>{{ __('Remote / delivery sales') }}</span>
                            <svg class="size-3.5 shrink-0 text-chrome-300 rtl:rotate-180" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.168 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd"/>
                            </svg>
                        </a>
                    @endif

                    {{-- App's own feature toggles (admins only — set in AppSwitcher). --}}
                    @if ($settingsUrl)
                        <div class="my-1 border-t border-chrome-100"></div>
                        <a href="{{ $settingsUrl }}"
                            class="flex items-center gap-2 px-3 py-1.5 text-sm text-chrome-700 hover:bg-chrome-100">
                            <svg class="size-4 shrink-0 text-chrome-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fill-rule="evenodd" d="M7.84 1.804A1 1 0 0 1 8.82 1h2.36a1 1 0 0 1 .98.804l.331 1.652a6.993 6.993 0 0 1 1.929 1.115l1.598-.54a1 1 0 0 1 1.186.447l1.18 2.044a1 1 0 0 1-.205 1.251l-1.267 1.113a7.047 7.047 0 0 1 0 2.228l1.267 1.113a1 1 0 0 1 .206 1.25l-1.18 2.045a1 1 0 0 1-1.187.447l-1.598-.54a6.993 6.993 0 0 1-1.929 1.115l-.33 1.652a1 1 0 0 1-.98.804H8.82a1 1 0 0 1-.98-.804l-.331-1.652a6.993 6.993 0 0 1-1.929-1.115l-1.598.54a1 1 0 0 1-1.186-.447l-1.18-2.044a1 1 0 0 1 .205-1.251l1.267-1.114a7.05 7.05 0 0 1 0-2.227L1.821 7.773a1 1 0 0 1-.206-1.25l1.18-2.045a1 1 0 0 1 1.187-.447l1.598.54A6.992 6.992 0 0 1 7.51 3.456l.33-1.652ZM10 13a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z" clip-rule="evenodd"/>
                            </svg>
                            {{ __('Settings') }}
                        </a>
                    @endif
                </div>
            </div>
        @else
            {{-- App with no model menu → plain home link (current behaviour). --}}
            <a href="{{ $homeUrl }}"
                @class([
                    'flex shrink-0 items-center gap-1.5 rounded-md px-2.5 py-1.5 text-sm whitespace-nowrap transition-colors',
                    'bg-black/10 font-medium text-chrome-900' => $isActive,
                    'text-chrome-800 hover:bg-black/10 hover:text-chrome-900' => ! $isActive,
                ])
                @if ($isActive) aria-current="page" @endif
                title="{{ $label }}">
                <svg class="size-4 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    {!! $iconBody !!}
                </svg>
                <span class="hidden sm:inline">{{ $label }}</span>
            </a>
        @endif
    @endforeach
</nav>
