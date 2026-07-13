<div class="mx-auto max-w-4xl p-4 sm:p-6" x-data="{ tab: '{{ array_key_first($tabs) ?? ($reportTab ? '__reports' : '') }}' }">
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

    @if (count($tabs) === 0 && ! $reportTab && ! $userTab)
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

            {{-- Daily Report — its own tab (a recipient list, not an
                 ir_config_parameter group), admin-only. Stable ASCII tab
                 key so the localised label can't break the Alpine state. --}}
            @if ($reportTab)
                <button type="button" @click="tab = '__reports'"
                    :class="tab === '__reports'
                        ? 'border-primary-600 text-primary-700'
                        : 'border-transparent text-chrome-500 hover:text-chrome-800'"
                    class="-mb-px border-b-2 px-4 py-2 text-sm font-medium">
                    {{ __('Daily Report') }}
                </button>
            @endif

            {{-- Users — admin-only account creation + view-only access grants.
                 Stable ASCII tab key so the localised label can't break state. --}}
            @if ($userTab)
                <button type="button" @click="tab = '__users'"
                    :class="tab === '__users'
                        ? 'border-primary-600 text-primary-700'
                        : 'border-transparent text-chrome-500 hover:text-chrome-800'"
                    class="-mb-px border-b-2 px-4 py-2 text-sm font-medium">
                    {{ __('Users') }}
                </button>
            @endif
        </div>

        @foreach ($tabs as $group => $ids)
            <div x-show="tab === '{{ $group }}'" x-cloak
                class="space-y-5 rounded-xl bg-white p-6 shadow-sm ring-1 ring-chrome-900/5">
                {{-- Appearance — the look of THIS DATABASE (branding, not a personal
                     preference): every user of the database sees it, and each database
                     keeps its own. SUPER-ADMIN ONLY. Applies live via setTheme() /
                     setAccent() → the layout's theme-changed / accent-changed hooks. --}}
                @if ($group === 'General' && $canSetAppearance)
                    <div class="grid items-start gap-2 border-b border-chrome-100 pb-5 sm:grid-cols-3">
                        <label class="pt-2 text-sm font-medium text-chrome-800">
                            {{ __('Appearance') }}
                            <span class="mt-0.5 block text-xs font-normal text-chrome-400">{{ __('Theme and accent colour for this database. Everyone who uses it sees this.') }}</span>
                        </label>
                        <div class="sm:col-span-2">
                            <div class="flex flex-wrap items-center gap-x-5 gap-y-3">
                                {{-- Theme: light / dark / system --}}
                                <div class="inline-flex rounded-lg border border-chrome-300 bg-chrome-50 p-0.5">
                                    @php
                                        $themeOptions = [
                                            ['value' => 'light', 'label' => __('Light'), 'icon' => 'M10 3a1 1 0 0 1 1 1v1a1 1 0 1 1-2 0V4a1 1 0 0 1 1-1Zm0 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm7-4a1 1 0 0 1-1 1h-1a1 1 0 1 1 0-2h1a1 1 0 0 1 1 1ZM5 10a1 1 0 0 1-1 1H3a1 1 0 1 1 0-2h1a1 1 0 0 1 1 1Zm10.66-5.66a1 1 0 0 1 0 1.41l-.7.71a1 1 0 1 1-1.42-1.42l.71-.7a1 1 0 0 1 1.41 0ZM6.46 13.54a1 1 0 0 1 0 1.41l-.71.71a1 1 0 0 1-1.41-1.42l.7-.7a1 1 0 0 1 1.42 0Zm9.19 1.41a1 1 0 0 1-1.41 0l-.71-.7a1 1 0 0 1 1.42-1.42l.7.71a1 1 0 0 1 0 1.41ZM6.46 6.46a1 1 0 0 1-1.42 0l-.7-.71a1 1 0 0 1 1.41-1.41l.71.7a1 1 0 0 1 0 1.42ZM10 15a1 1 0 0 1 1 1v1a1 1 0 1 1-2 0v-1a1 1 0 0 1 1-1Z'],
                                            ['value' => 'dark', 'label' => __('Dark'), 'icon' => 'M7.5 2.9a1 1 0 0 1 .2 1.09A6 6 0 0 0 15 12.3a1 1 0 0 1 1.3 1.29A8 8 0 1 1 6.42 2.7a1 1 0 0 1 1.09.2Z'],
                                            ['value' => 'system', 'label' => __('System'), 'icon' => 'M3 5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2h-3v2h1a1 1 0 1 1 0 2H7a1 1 0 1 1 0-2h1v-2H5a2 2 0 0 1-2-2V5Zm2 0v6h10V5H5Z'],
                                        ];
                                    @endphp
                                    @foreach ($themeOptions as $opt)
                                        <button type="button" wire:click="setTheme('{{ $opt['value'] }}')"
                                            @class([
                                                'inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium transition',
                                                'bg-primary-400 text-chrome-900 shadow-sm' => $theme === $opt['value'],
                                                'text-chrome-600 hover:bg-chrome-200' => $theme !== $opt['value'],
                                            ])>
                                            <svg class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="{{ $opt['icon'] }}" clip-rule="evenodd"/></svg>
                                            {{ $opt['label'] }}
                                        </button>
                                    @endforeach
                                </div>

                                {{-- Accent colour swatches — sit BESIDE the theme control.
                                     The hex is inline so each shows its true colour whatever
                                     the current accent is; picking one calls setAccent() which
                                     swaps `data-accent` on <html> and re-tints the app live. --}}
                                @php
                                    $accentSwatches = [
                                        ['value' => 'yellow', 'label' => __('Yellow'), 'hex' => '#f5ef1a'],
                                        ['value' => 'amber', 'label' => __('Amber'), 'hex' => '#fbbf24'],
                                        ['value' => 'orange', 'label' => __('Orange'), 'hex' => '#fb923c'],
                                        ['value' => 'red', 'label' => __('Red'), 'hex' => '#ef4444'],
                                        ['value' => 'pink', 'label' => __('Pink'), 'hex' => '#f472b6'],
                                        ['value' => 'violet', 'label' => __('Violet'), 'hex' => '#a78bfa'],
                                        ['value' => 'sky', 'label' => __('Sky'), 'hex' => '#38bdf8'],
                                        ['value' => 'emerald', 'label' => __('Emerald'), 'hex' => '#34d399'],
                                    ];
                                @endphp
                                <div class="flex flex-wrap items-center gap-2">
                                    @foreach ($accentSwatches as $sw)
                                        <button type="button" wire:click="setAccent('{{ $sw['value'] }}')"
                                            title="{{ $sw['label'] }}" aria-label="{{ $sw['label'] }}"
                                            @class([
                                                'size-6 rounded-full ring-offset-2 ring-offset-white transition dark:ring-offset-[#1e2126]',
                                                'ring-2 ring-chrome-700 dark:ring-[#e2e5e9]' => $accent === $sw['value'],
                                                'ring-1 ring-black/10 hover:ring-2 hover:ring-chrome-300 dark:hover:ring-[#3d434b]' => $accent !== $sw['value'],
                                            ])
                                            style="background-color: {{ $sw['hex'] }}"></button>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>
                @endif
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
                                        {{ $row['value'] ? 'bg-primary-500' : 'bg-chrome-300' }}">
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
                            @elseif ($row['type'] === 'image')
                                {{-- Image-type setting (e.g. company.logo). The
                                     upload goes direct to FormImageUploadController,
                                     same pattern as FormView's image widget — no
                                     Livewire two-phase temp file. The returned path
                                     is written to `$wire.imagePaths.<key>` and the
                                     SettingsPage::save() merges it into the row's
                                     value before flushing to ir_config_parameter.
                                     `bucket` is hard-coded here to `company` because
                                     the only image setting today is the logo; if
                                     another image setting is added we can derive the
                                     bucket from the key. --}}
                                @php
                                    $previewUrl = (is_string($row['value']) && $row['value'] !== '')
                                        ? \Illuminate\Support\Facades\Storage::disk('public')->url($row['value'])
                                        : null;
                                    // Guard: file might be missing on disk (e.g. a
                                    // bucket-wipe regression). Treat as no preview.
                                    if ($previewUrl !== null && ! \Illuminate\Support\Facades\Storage::disk('public')->exists($row['value'])) {
                                        $previewUrl = null;
                                    }
                                @endphp
                                <div class="flex items-start gap-4"
                                    x-data="{
                                        busy: false,
                                        error: '',
                                        previewUrl: @js($previewUrl),
                                        async upload(e) {
                                            const file = e.target.files[0];
                                            if (!file) return;
                                            this.busy = true;
                                            this.error = '';
                                            const data = new FormData();
                                            data.append('file', file);
                                            data.append('bucket', 'company');
                                            try {
                                                const r = await fetch(@js(route('form.upload-image')), {
                                                    method: 'POST',
                                                    headers: {
                                                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                                                        'Accept': 'application/json',
                                                    },
                                                    body: data,
                                                    credentials: 'same-origin',
                                                });
                                                if (!r.ok) {
                                                    const j = await r.json().catch(() => ({}));
                                                    this.error = (j.errors && j.errors.file && j.errors.file[0]) || j.message || (@js(__('Upload failed.')));
                                                    return;
                                                }
                                                const j = await r.json();
                                                this.previewUrl = j.url;
                                                await $wire.set(@js('imagePaths.' . $i), j.path);
                                            } catch (err) {
                                                this.error = err.message || (@js(__('Upload failed.')));
                                            } finally {
                                                this.busy = false;
                                            }
                                        },
                                    }">
                                    <span class="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-chrome-100 ring-1 ring-chrome-200 text-chrome-400">
                                        <template x-if="previewUrl">
                                            <img :src="previewUrl" alt="" class="size-full object-contain p-1">
                                        </template>
                                        <template x-if="!previewUrl">
                                            <svg class="size-7" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M1 5.25A2.25 2.25 0 0 1 3.25 3h13.5A2.25 2.25 0 0 1 19 5.25v9.5A2.25 2.25 0 0 1 16.75 17H3.25A2.25 2.25 0 0 1 1 14.75v-9.5Zm1.5 5.81v3.69c0 .414.336.75.75.75h13.5a.75.75 0 0 0 .75-.75v-2.69l-2.22-2.219a.75.75 0 0 0-1.06 0L10 14.06l-3.969-3.97a.75.75 0 0 0-1.06 0L2.5 11.06ZM6.625 7a1.125 1.125 0 1 0 0 2.25 1.125 1.125 0 0 0 0-2.25Z" clip-rule="evenodd"/></svg>
                                        </template>
                                    </span>
                                    <div class="flex flex-1 flex-col gap-1">
                                        <input type="file" accept="image/*" @change="upload($event)"
                                            class="text-sm text-chrome-600 file:me-3 file:rounded-md file:border-0 file:bg-chrome-100 file:px-3 file:py-1.5 file:text-sm file:font-medium hover:file:bg-chrome-200">
                                        <p class="text-xs text-chrome-400">{{ __('Accepted: JPG, PNG, GIF, WebP, AVIF, HEIC, BMP · max 4 MB') }}</p>
                                        <p x-show="busy" class="text-xs text-chrome-400">{{ __('Uploading…') }}</p>
                                        <p x-show="error" x-text="error" class="text-xs text-red-600"></p>
                                    </div>
                                </div>
                            @else
                                <input type="text" wire:model="form.{{ $i }}.value" class="o-input">
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endforeach

        {{-- Daily Report tab panel — recipient manager for the automated
             6:10 AM sales + stock PDF. Moved here from the Dashboard. Its
             add/remove/send actions fire immediately (not via the top
             Save button, which only persists ir_config_parameter rows). --}}
        @if ($reportTab)
            <div x-show="tab === '__reports'" x-cloak
                class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-chrome-900/5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h2 class="text-sm font-semibold text-chrome-800">{{ __('Daily report email list') }}</h2>
                        <p class="mt-1 text-sm text-chrome-500">
                            {{ __('The sales + stock PDF is emailed to these addresses automatically every day at 6:10 AM.') }}
                        </p>
                    </div>
                    <button type="button" wire:click="sendNow" wire:loading.attr="disabled"
                        class="o-btn-ghost shrink-0 text-sm">
                        <span wire:loading.remove wire:target="sendNow">{{ __('Send now') }}</span>
                        <span wire:loading wire:target="sendNow">{{ __('Sending…') }}</span>
                    </button>
                </div>

                @if (session('report_sent'))
                    <p class="mt-3 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-700">{{ session('report_sent') }}</p>
                @endif

                {{-- Add recipient --}}
                <form wire:submit="addRecipient" class="mt-4 flex flex-wrap gap-2">
                    <input type="email" wire:model="newRecipientEmail" placeholder="name@example.com"
                        class="o-input max-w-xs text-sm" autocomplete="off">
                    <button type="submit" class="o-btn-primary text-sm">{{ __('Add') }}</button>
                </form>
                @error('newRecipientEmail') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

                {{-- List --}}
                <ul class="mt-4 divide-y divide-chrome-100 rounded-lg border border-chrome-100">
                    @forelse ($recipients as $recipient)
                        <li wire:key="rcpt-{{ $recipient->id }}" class="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                            <span class="truncate text-chrome-700">{{ $recipient->email }}</span>
                            <button type="button" wire:click="removeRecipient({{ $recipient->id }})"
                                wire:confirm="{{ __('Remove :email from the report list?', ['email' => $recipient->email]) }}"
                                class="shrink-0 text-xs text-red-600 hover:underline">{{ __('remove') }}</button>
                        </li>
                    @empty
                        <li class="px-3 py-4 text-center text-sm text-chrome-400">{{ __('No recipients yet — add one above.') }}</li>
                    @endforelse
                </ul>
            </div>
        @endif

        {{-- Users tab panel — embeds the admin-only UserManager component
             (staff account creation + view-only app/database access). --}}
        @if ($userTab)
            <div x-show="tab === '__users'" x-cloak
                class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-chrome-900/5">
                @livewire(\App\Livewire\Settings\UserManager::class)
            </div>
        @endif
    @endif
</div>
