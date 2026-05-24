<nav class="flex flex-col gap-1 p-2 text-sm">
    <a href="{{ url('/') }}"
        class="flex items-center gap-3 rounded-md px-2.5 py-2 text-chrome-700 hover:bg-chrome-200">
        <svg class="size-5 shrink-0 text-chrome-500" viewBox="0 0 20 20" fill="currentColor"><path d="M10 2 2 8.5V18a1 1 0 0 0 1 1h4v-5h6v5h4a1 1 0 0 0 1-1V8.5L10 2Z"/></svg>
        <span x-show="!collapsed" x-cloak class="truncate font-medium">{{ __('Dashboard') }}</span>
    </a>

    @if ($isAdmin)
        <a href="{{ url('/app/settings') }}"
            class="flex items-center gap-3 rounded-md px-2.5 py-2 text-chrome-700 hover:bg-chrome-200">
            {{-- Heroicons mini cog-6-tooth, canonical path. Earlier hand-
                 rolled version overshot the 0-20 viewBox and rendered
                 visibly clipped at the icon's edges. --}}
            <svg class="size-5 shrink-0 text-chrome-500" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M8.34 1.804A1 1 0 0 1 9.32 1h1.36a1 1 0 0 1 .98.804l.295 1.473c.497.144.971.342 1.416.587l1.25-.834a1 1 0 0 1 1.262.125l.962.962a1 1 0 0 1 .125 1.262l-.834 1.25c.245.445.443.919.587 1.416l1.473.294a1 1 0 0 1 .804.98v1.361a1 1 0 0 1-.804.98l-1.473.295a6.95 6.95 0 0 1-.587 1.416l.834 1.25a1 1 0 0 1-.125 1.262l-.962.962a1 1 0 0 1-1.262.125l-1.25-.834a6.953 6.953 0 0 1-1.416.587l-.294 1.473a1 1 0 0 1-.98.804H9.32a1 1 0 0 1-.98-.804l-.295-1.473a6.957 6.957 0 0 1-1.416-.587l-1.25.834a1 1 0 0 1-1.262-.125l-.962-.962a1 1 0 0 1-.125-1.262l.834-1.25a6.957 6.957 0 0 1-.587-1.416l-1.473-.294A1 1 0 0 1 1 10.681V9.32a1 1 0 0 1 .804-.98l1.473-.295c.144-.497.342-.971.587-1.416l-.834-1.25a1 1 0 0 1 .125-1.262l.962-.962A1 1 0 0 1 5.38 3.03l1.25.834a6.957 6.957 0 0 1 1.416-.587l.294-1.473ZM13 10a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" clip-rule="evenodd"/></svg>
            <span x-show="!collapsed" x-cloak class="truncate font-medium">{{ __('Settings') }}</span>
        </a>
    @endif

    @if ($module)
        <div x-show="!collapsed" x-cloak
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
                <span x-show="!collapsed" x-cloak class="truncate">{{ __($entry['label']) }}</span>
            </a>
        @empty
            <p x-show="!collapsed" x-cloak class="px-2.5 py-2 text-xs text-chrome-400">
                {{ __('No views registered yet.') }}
            </p>
        @endforelse
    @else
        <div x-show="!collapsed" x-cloak
            class="mt-3 px-2.5 text-[11px] font-semibold uppercase tracking-wide text-chrome-400">
            {{ __('Workspace') }}
        </div>
        <span class="flex items-center gap-3 rounded-md px-2.5 py-2 text-chrome-400">
            <svg class="size-5 shrink-0" viewBox="0 0 20 20" fill="currentColor"><path d="M10 3a7 7 0 1 0 0 14 7 7 0 0 0 0-14Zm1 10H9V9h2v4Zm0-6H9V5h2v2Z"/></svg>
            <span x-show="!collapsed" x-cloak class="truncate text-xs">{{ __('Pick an app to begin') }}</span>
        </span>
    @endif
</nav>
