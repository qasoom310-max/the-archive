<div class="mx-auto max-w-4xl p-6" x-data="{ tab: '{{ array_key_first($tabs) ?? '' }}' }">
    <div class="mb-5 flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold text-chrome-900">{{ __('Settings') }}</h1>
            <p class="text-sm text-chrome-500">{{ __('Central configuration for the whole system.') }}</p>
        </div>
        <button wire:click="save" class="o-btn-primary">
            <span wire:loading.remove wire:target="save">{{ __('Save') }}</span>
            <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
        </button>
    </div>

    @include('partials.settings-nav', ['active' => 'general'])

    @if ($saved)
        <div class="mb-4 rounded-lg bg-emerald-50 px-4 py-2 text-sm font-medium text-emerald-700 ring-1 ring-emerald-200">
            {{ __('Settings saved.') }}
        </div>
    @endif

    @if (count($tabs) === 0)
        <p class="rounded-xl border border-dashed border-chrome-300 bg-white p-10 text-center text-sm text-chrome-400">
            {{ __('No configurable settings yet.') }}
        </p>
    @else
        <div class="mb-5 flex flex-wrap gap-1 border-b border-chrome-200">
            @foreach ($tabs as $group => $ids)
                <button type="button" @click="tab = '{{ $group }}'"
                    :class="tab === '{{ $group }}'
                        ? 'border-primary-600 text-primary-700'
                        : 'border-transparent text-chrome-500 hover:text-chrome-800'"
                    class="-mb-px border-b-2 px-4 py-2 text-sm font-medium">
                    {{ __($group) }}
                </button>
            @endforeach
        </div>

        @foreach ($tabs as $group => $ids)
            <div x-show="tab === '{{ $group }}'" x-cloak
                class="space-y-5 rounded-xl bg-white p-6 shadow-sm ring-1 ring-chrome-900/5">
                @foreach ($ids as $i)
                    @php $row = $form[$i]; @endphp
                    <div wire:key="setting-{{ $i }}"
                        class="grid items-start gap-2 sm:grid-cols-3">
                        <label class="pt-2 text-sm font-medium text-chrome-800">
                            {{ __($row['label']) }}
                            @if ($row['description'])
                                <span class="mt-0.5 block text-xs font-normal text-chrome-400">{{ __($row['description']) }}</span>
                            @endif
                        </label>
                        <div class="sm:col-span-2">
                            @if ($row['type'] === 'bool')
                                <button type="button"
                                    wire:click="$set('form.{{ $i }}.value', {{ $row['value'] ? 'false' : 'true' }})"
                                    class="relative inline-flex h-6 w-11 items-center rounded-full transition
                                        {{ $row['value'] ? 'bg-primary-600' : 'bg-chrome-300' }}">
                                    <span class="inline-block size-4 transform rounded-full bg-white transition
                                        {{ $row['value'] ? 'translate-x-6' : 'translate-x-1' }}"></span>
                                </button>
                            @elseif (isset($selects[$row['key']]))
                                {{-- Enumerated picker — Alpine-driven combobox so it
                                     can host a search field at the top + a filtered,
                                     keyboard-friendly list (the native <select> can't
                                     be restyled past the OS default). Two-way bound to
                                     `form.{i}.value` via `@entangle`, so picking a row
                                     pushes through Livewire's normal save() path with
                                     no extra wire-up. The options array is dumped
                                     inline via `Js::from()` — keeps the registry
                                     server-side and lets the JS filter run locally. --}}
                                <div x-data="{
                                        open: false,
                                        query: '',
                                        options: {{ \Illuminate\Support\Js::from($selects[$row['key']]) }},
                                        selected: @entangle('form.' . $i . '.value'),
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
                                            return m ? m.label : @js(__('— Select —'));
                                        },
                                        pick(value) {
                                            this.selected = value;
                                            this.open = false;
                                            this.query = '';
                                        },
                                    }"
                                    @click.outside="open = false"
                                    @keydown.escape.window="open = false"
                                    class="relative max-w-sm">

                                    <button type="button"
                                        @click="open = !open; if (open) $nextTick(() => $refs.cbSearch.focus())"
                                        :class="open ? 'border-primary-500 ring-2 ring-primary-500/20' : 'border-chrome-300 hover:border-chrome-400'"
                                        class="flex w-full items-center justify-between rounded-md border bg-white px-3 py-2 text-start text-sm text-chrome-800 transition focus:outline-none">
                                        <span x-text="currentLabel()"></span>
                                        <svg class="ms-2 size-4 shrink-0 text-chrome-400 transition" :class="open ? 'rotate-180' : ''"
                                            viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m6 9 6 6 6-6" />
                                        </svg>
                                    </button>

                                    <div x-show="open" x-cloak x-transition.opacity.duration.100ms
                                        class="absolute start-0 end-0 z-30 mt-1 overflow-hidden rounded-lg border border-chrome-200 bg-white shadow-pop">
                                        <div class="border-b border-chrome-100 p-2">
                                            <div class="relative">
                                                <svg class="pointer-events-none absolute start-2 top-1/2 size-4 -translate-y-1/2 text-chrome-400"
                                                    viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                    <path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 1 0 3.5 9.74l3.38 3.38a1 1 0 0 0 1.42-1.42l-3.38-3.38A5.5 5.5 0 0 0 9 3.5ZM5.5 9a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0Z" clip-rule="evenodd"/>
                                                </svg>
                                                <input x-ref="cbSearch" x-model="query" type="text" placeholder="{{ __('Search…') }}"
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
                            @elseif ($row['type'] === 'number')
                                <input type="number" step="0.01" wire:model="form.{{ $i }}.value" class="o-input max-w-xs">
                            @else
                                <input type="text" wire:model="form.{{ $i }}.value" class="o-input">
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endforeach
    @endif
</div>
