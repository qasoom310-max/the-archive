<nav class="flex flex-col gap-1 p-2 text-sm">
    <a href="{{ url('/') }}"
        class="flex items-center gap-3 rounded-md px-2.5 py-2 text-chrome-700 hover:bg-chrome-200">
        <svg class="size-5 shrink-0 text-chrome-500" viewBox="0 0 20 20" fill="currentColor"><path d="M10 2 2 8.5V18a1 1 0 0 0 1 1h4v-5h6v5h4a1 1 0 0 0 1-1V8.5L10 2Z"/></svg>
        <span x-show="!collapsed" x-cloak class="truncate font-medium">{{ __('Dashboard') }}</span>
    </a>

    @if ($isAdmin)
        <a href="{{ url('/app/settings') }}"
            class="flex items-center gap-3 rounded-md px-2.5 py-2 text-chrome-700 hover:bg-chrome-200">
            <svg class="size-5 shrink-0 text-chrome-500" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8.34 1.8a1 1 0 0 1 .95-.7h1.42a1 1 0 0 1 .95.7l.3.96a6.97 6.97 0 0 1 1.46.84l.98-.23a1 1 0 0 1 1.1.45l.71 1.23a1 1 0 0 1-.15 1.2l-.7.7c.05.28.08.56.08.85s-.03.57-.08.85l.7.7a1 1 0 0 1 .15 1.2l-.71 1.23a1 1 0 0 1-1.1.45l-.98-.23c-.45.35-.94.63-1.46.84l-.3.96a1 1 0 0 1-.95.7H9.29a1 1 0 0 1-.95-.7l-.3-.96a6.97 6.97 0 0 1-1.46-.84l-.98.23a1 1 0 0 1-1.1-.45l-.71-1.23a1 1 0 0 1 .15-1.2l.7-.7A5.07 5.07 0 0 1 4.46 10c0-.29.03-.57.08-.85l-.7-.7a1 1 0 0 1-.15-1.2l.71-1.23a1 1 0 0 1 1.1-.45l.98.23c.45-.35.94-.63 1.46-.84l.3-.96ZM10 13a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z" clip-rule="evenodd"/></svg>
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
