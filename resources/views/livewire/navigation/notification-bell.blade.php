<div class="relative" x-data="{ open: false }" @keydown.escape.window="open = false" wire:poll.90s>
    <button type="button" @click="open = !open"
        class="relative flex size-9 items-center justify-center rounded-md text-chrome-800 hover:bg-black/10"
        title="{{ __('Notifications') }}" aria-label="{{ __('Notifications') }}">
        <svg class="size-5" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" />
        </svg>
        @if ($count > 0)
            <span class="absolute -top-0.5 -end-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-red-600 px-1 text-[10px] font-bold leading-none text-white ring-2 ring-primary-400">{{ $count > 9 ? '9+' : $count }}</span>
        @endif
    </button>

    {{-- w-[min(20rem,…)] so the panel never exceeds the space to the left of the
         bell on a phone (the bell isn't the right-most control) — avoids clipping
         + horizontal page scroll. --}}
    <div x-show="open" x-cloak x-transition.origin.top.right @click.outside="open = false"
        class="absolute end-0 z-50 mt-2 w-[min(20rem,calc(100vw-1rem))] overflow-hidden rounded-xl bg-white text-chrome-700 shadow-pop ring-1 ring-chrome-900/5">
        <div class="flex items-center justify-between border-b border-chrome-100 px-3 py-2.5">
            <span class="text-sm font-semibold text-chrome-800">{{ __('Notifications') }}</span>
            @if ($count > 0)
                <span class="rounded-full bg-chrome-100 px-2 py-0.5 text-[11px] font-bold text-chrome-600">{{ $count }}</span>
            @endif
        </div>

        <div class="max-h-96 overflow-y-auto">
            @forelse ($items as $item)
                @php $dot = ['critical' => 'bg-red-500', 'warning' => 'bg-amber-500', 'info' => 'bg-sky-500'][$item->level] ?? 'bg-chrome-400'; @endphp
                <a href="{{ $item->url }}" wire:navigate @click="open = false"
                    class="flex items-start gap-2.5 border-b border-chrome-50 px-3 py-2.5 transition hover:bg-chrome-50">
                    <span class="mt-1.5 size-2 shrink-0 rounded-full {{ $dot }}"></span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-medium text-chrome-800">{{ $item->title }}</span>
                        @if ($item->description)
                            <span class="block truncate text-xs text-chrome-500">{{ $item->description }}</span>
                        @endif
                        @if ($item->group)
                            <span class="mt-0.5 inline-block text-[10px] font-semibold uppercase tracking-wide text-chrome-400">{{ $item->group }}</span>
                        @endif
                    </span>
                </a>
            @empty
                <div class="px-3 py-10 text-center">
                    <svg class="mx-auto size-7 text-chrome-300" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                    <p class="mt-2 text-sm text-chrome-400">{{ __('You’re all caught up.') }}</p>
                </div>
            @endforelse
        </div>
    </div>
</div>
