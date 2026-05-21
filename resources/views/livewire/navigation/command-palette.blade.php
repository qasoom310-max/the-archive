<div
    x-data="{
        open: false,
        active: 0,
        openPalette() { this.open = true; this.active = 0; $nextTick(() => $refs.input && $refs.input.focus()); },
        items() { return this.$refs.list ? this.$refs.list.querySelectorAll('[data-cmd]') : []; },
        move(dir) { const n = this.items().length; if (!n) return; this.active = (this.active + dir + n) % n; },
        go() { const el = this.items()[this.active]; if (el) window.location.assign(el.getAttribute('href')); },
    }"
    @keydown.window.cmd.k.prevent="openPalette()"
    @keydown.window.ctrl.k.prevent="openPalette()"
    @open-command-palette.window="openPalette()"
    @keydown.escape.window="open = false"
>
    <template x-teleport="body">
        <div x-show="open" x-cloak class="fixed inset-0 z-50">
            <div class="absolute inset-0 bg-chrome-900/40 backdrop-blur-sm"
                @click="open = false" x-transition.opacity></div>

            <div x-show="open" x-transition
                class="relative mx-auto mt-[12vh] w-full max-w-xl overflow-hidden rounded-xl bg-white shadow-pop ring-1 ring-chrome-900/10">
                <div class="flex items-center gap-2 border-b border-chrome-200 px-4">
                    <svg class="size-4 text-chrome-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 1 0 3.5 9.74l3.38 3.38a1 1 0 0 0 1.42-1.42l-3.38-3.38A5.5 5.5 0 0 0 9 3.5ZM5.5 9a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0Z" clip-rule="evenodd"/></svg>
                    <input x-ref="input" wire:model.live.debounce.200ms="query"
                        @input="active = 0"
                        @keydown.arrow-down.prevent="move(1)"
                        @keydown.arrow-up.prevent="move(-1)"
                        @keydown.enter.prevent="go()"
                        type="text" placeholder="{{ __('Search apps, records, actions…') }}"
                        class="w-full border-0 py-3 text-sm focus:ring-0">
                    <kbd class="rounded border border-chrome-300 bg-chrome-100 px-1.5 py-0.5 text-[10px] text-chrome-500">ESC</kbd>
                </div>

                <ul x-ref="list" class="max-h-80 overflow-y-auto p-2">
                    @forelse ($results as $i => $item)
                        <li>
                            <a data-cmd href="{{ $item['url'] }}"
                                @mouseenter="active = {{ $i }}"
                                :class="active === {{ $i }} ? 'bg-primary-50 text-primary-800' : 'text-chrome-700'"
                                class="flex items-center justify-between rounded-lg px-3 py-2">
                                <span class="flex items-center gap-3">
                                    <span class="flex size-7 items-center justify-center rounded-md bg-chrome-100 text-xs font-semibold text-chrome-500">
                                        {{ \Illuminate\Support\Str::substr($item['label'], 0, 1) }}
                                    </span>
                                    <span>
                                        <span class="block text-sm font-medium">{{ __($item['label']) }}</span>
                                        <span class="block text-xs text-chrome-400">{{ __($item['hint']) }}</span>
                                    </span>
                                </span>
                                <span class="o-chip bg-chrome-100 text-chrome-500">{{ __($item['group']) }}</span>
                            </a>
                        </li>
                    @empty
                        <li class="px-3 py-8 text-center text-sm text-chrome-400">{{ __('No matches.') }}</li>
                    @endforelse
                </ul>

                <div class="flex items-center gap-4 border-t border-chrome-200 bg-chrome-50 px-4 py-2 text-[11px] text-chrome-400">
                    <span><kbd class="font-sans">↑↓</kbd> {{ __('navigate') }}</span>
                    <span><kbd class="font-sans">↵</kbd> {{ __('open') }}</span>
                    <span class="ms-auto"><kbd class="font-sans">⌘K</kbd> / <kbd class="font-sans">Ctrl K</kbd></span>
                </div>
            </div>
        </div>
    </template>
</div>
