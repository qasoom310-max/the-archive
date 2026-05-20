@php
    $tileColors = [
        'bg-primary-600', 'bg-emerald-600', 'bg-sky-600', 'bg-amber-600',
        'bg-rose-600', 'bg-indigo-600', 'bg-teal-600', 'bg-fuchsia-600',
    ];
@endphp

<div x-data="{ open: false }" class="relative" @keydown.escape.window="open = false">
    <button type="button" @click="open = !open"
        class="flex size-9 items-center justify-center rounded-md text-chrome-200 hover:bg-white/10"
        title="Apps">
        <svg class="size-5" viewBox="0 0 20 20" fill="currentColor">
            <path d="M3 3h4v4H3V3Zm0 6h4v4H3V9Zm0 6h4v4H3v-4Zm6-12h4v4H9V3Zm0 6h4v4H9V9Zm0 6h4v4H9v-4Zm6-12h4v4h-4V3Zm0 6h4v4h-4V9Zm0 6h4v4h-4v-4Z"/>
        </svg>
    </button>

    <div x-show="open" x-cloak x-transition.origin.top.left
        @click.outside="open = false"
        class="absolute left-0 z-40 mt-2 w-80 origin-top-left rounded-xl bg-white p-3 shadow-pop ring-1 ring-chrome-900/5">
        @if ($apps->isEmpty())
            <p class="px-2 py-6 text-center text-sm text-chrome-400">
                No applications installed.<br>
                <span class="text-xs">Run <code class="rounded bg-chrome-100 px-1">php artisan module:install &lt;name&gt;</code></span>
            </p>
        @else
            <div class="grid grid-cols-3 gap-1">
                @foreach ($apps as $app)
                    @php $color = $tileColors[crc32($app->name) % count($tileColors)]; @endphp
                    <a href="{{ url('/app/' . $app->name) }}"
                        class="flex flex-col items-center gap-2 rounded-lg p-3 text-center hover:bg-chrome-100">
                        <span class="flex size-12 items-center justify-center rounded-xl {{ $color }} text-lg font-bold text-white">
                            {{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($app->display_name, 0, 2)) }}
                        </span>
                        <span class="line-clamp-2 text-xs font-medium text-chrome-700">{{ $app->display_name }}</span>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</div>
