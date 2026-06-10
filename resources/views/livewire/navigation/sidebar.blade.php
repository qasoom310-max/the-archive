{{--
    Labels gated by `x-show="mobileOpen || !collapsed"`. The `collapsed` flag
    is `$persist(false)` from the desktop layout, so a user who last collapsed
    the sidebar on desktop comes back on phone with that flag still true —
    without the extra `mobileOpen` clause, the mobile drawer would render as
    icon-only despite being open. Drawer-open always forces labels visible.
--}}
<nav class="flex flex-col gap-1 p-2 text-sm">
    <a href="{{ url('/') }}"
        class="flex items-center gap-3 rounded-md px-2.5 py-2 text-chrome-700 hover:bg-chrome-200">
        <svg class="size-5 shrink-0 text-chrome-500" viewBox="0 0 20 20" fill="currentColor"><path d="M10 2 2 8.5V18a1 1 0 0 0 1 1h4v-5h6v5h4a1 1 0 0 0 1-1V8.5L10 2Z"/></svg>
        <span x-show="mobileOpen || !collapsed" x-cloak class="truncate font-medium">{{ __('Dashboard') }}</span>
    </a>

    @if ($module)
        <div x-show="mobileOpen || !collapsed" x-cloak
            class="mt-3 px-2.5 text-[11px] font-semibold uppercase tracking-wide text-chrome-400">
            {{ __('module.' . $module->name, [], app()->getLocale()) !== 'module.' . $module->name
                ? __('module.' . $module->name)
                : $module->display_name }}
        </div>

        @forelse ($entries as $entry)
            <a href="{{ $entry['url'] }}"
                class="flex items-center gap-3 rounded-md px-2.5 py-2 text-chrome-700 hover:bg-chrome-200">
                <span class="flex size-5 shrink-0 items-center justify-center rounded bg-primary-100 text-[10px] font-bold text-primary-700">
                    {{ \Illuminate\Support\Str::substr(__($entry['label']), 0, 1) }}
                </span>
                <span x-show="mobileOpen || !collapsed" x-cloak class="truncate">{{ __($entry['label']) }}</span>
            </a>
        @empty
            <p x-show="mobileOpen || !collapsed" x-cloak class="px-2.5 py-2 text-xs text-chrome-400">
                {{ __('No views registered yet.') }}
            </p>
        @endforelse
    @else
        <div x-show="mobileOpen || !collapsed" x-cloak
            class="mt-3 px-2.5 text-[11px] font-semibold uppercase tracking-wide text-chrome-400">
            {{ __('Workspace') }}
        </div>
        <span class="flex items-center gap-3 rounded-md px-2.5 py-2 text-chrome-400">
            <svg class="size-5 shrink-0" viewBox="0 0 20 20" fill="currentColor"><path d="M10 3a7 7 0 1 0 0 14 7 7 0 0 0 0-14Zm1 10H9V9h2v4Zm0-6H9V5h2v2Z"/></svg>
            <span x-show="mobileOpen || !collapsed" x-cloak class="truncate text-xs">{{ __('Pick an app to begin') }}</span>
        </span>
    @endif
</nav>
