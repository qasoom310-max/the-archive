{{--
    Searchable country dial-code picker. Drop-in replacement for the old
    native `<select wire:model="X">` in the POS terminal.

    Required vars (pass via @include):
      $wireModel — Livewire property name (e.g. 'countryCode'), bound via @entangle
      $countries — list<array{dial: string, label: string, country?: string}> from PosWhatsAppCountries

    Mirrors the canonical combobox in resources/views/livewire/pages/settings.blade.php
    so users get the same search / keyboard / RTL behaviour across the app.
    Inline `Js::from(...)` (NOT @json — memory: blade-json-vs-js-in-alpine-attrs)
    safely embeds the country list in the x-data attribute.
--}}
<div x-data="{
        open: false,
        query: '',
        options: {{ \Illuminate\Support\Js::from(array_map(fn($c) => ['value' => $c['dial'], 'label' => $c['label']], $countries)) }},
        selected: @entangle($wireModel),
        filtered() {
            const q = this.query.trim().toLowerCase();
            if (q === '') return this.options;
            return this.options.filter(o =>
                o.value.toLowerCase().includes(q) ||
                o.label.toLowerCase().includes(q)
            );
        },
        currentLabel() {
            const m = this.options.find(o => o.value === this.selected);
            return m ? m.label : @js(__('Select…'));
        },
        pick(value) {
            this.selected = value;
            this.open = false;
            this.query = '';
        },
    }"
    @click.outside="open = false"
    @keydown.escape.window="open = false"
    class="relative w-32 shrink-0">

    <button type="button"
        @click="open = !open; if (open) $nextTick(() => $refs.cpSearch.focus())"
        :class="open ? 'border-primary-500 ring-2 ring-primary-500/20' : 'border-chrome-300 hover:border-chrome-400'"
        class="flex w-full items-center justify-between truncate rounded-md border bg-white px-2 py-1.5 text-start text-sm text-chrome-800 transition focus:outline-none">
        <span class="truncate" x-text="currentLabel()"></span>
        <svg class="ms-1 size-4 shrink-0 text-chrome-400 transition" :class="open ? 'rotate-180' : ''"
            viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m6 9 6 6 6-6" />
        </svg>
    </button>

    {{-- Popover: w-64 so country labels like "Saudi Arabia (+966)" never truncate
         even when the trigger button is only w-32. Anchored start-0 so it lines
         up with the (narrower) button regardless of dir. --}}
    <div x-show="open" x-cloak x-transition.opacity.duration.100ms
        class="absolute start-0 z-50 mt-1 w-64 overflow-hidden rounded-lg border border-chrome-200 bg-white shadow-pop">
        <div class="border-b border-chrome-100 p-2">
            <div class="relative">
                <svg class="pointer-events-none absolute start-2 top-1/2 size-4 -translate-y-1/2 text-chrome-400"
                    viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 1 0 3.5 9.74l3.38 3.38a1 1 0 0 0 1.42-1.42l-3.38-3.38A5.5 5.5 0 0 0 9 3.5ZM5.5 9a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0Z" clip-rule="evenodd"/>
                </svg>
                <input x-ref="cpSearch" x-model="query" type="text" placeholder="{{ __('Search country…') }}"
                    class="w-full rounded-md border border-chrome-200 bg-chrome-50 py-1.5 ps-8 pe-2 text-sm text-chrome-800 placeholder:text-chrome-400 focus:border-primary-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-primary-500/20">
            </div>
        </div>
        <ul class="max-h-60 overflow-y-auto py-1 text-sm">
            <template x-for="opt in filtered()" :key="opt.value">
                <li>
                    <button type="button" @click="pick(opt.value)"
                        :class="opt.value === selected
                            ? 'bg-primary-50 font-medium text-primary-700'
                            : 'text-chrome-700 hover:bg-chrome-50'"
                        class="flex w-full items-center justify-between px-3 py-1.5 text-start">
                        <span x-text="opt.label"></span>
                        <svg x-show="opt.value === selected" x-cloak class="size-4 text-primary-600"
                            viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M16.7 5.3a1 1 0 0 1 0 1.4l-8 8a1 1 0 0 1-1.4 0l-4-4a1 1 0 1 1 1.4-1.4L8 12.6l7.3-7.3a1 1 0 0 1 1.4 0Z" clip-rule="evenodd"/>
                        </svg>
                    </button>
                </li>
            </template>
            <li x-show="filtered().length === 0" x-cloak
                class="px-3 py-6 text-center text-xs text-chrome-400">
                {{ __('No matches for') }} "<span x-text="query"></span>"
            </li>
        </ul>
    </div>
</div>
