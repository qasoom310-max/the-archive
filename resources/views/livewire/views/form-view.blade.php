@php
    $gridCols = $cols >= 2 ? 'sm:grid-cols-2' : 'grid-cols-1';
@endphp

<form wire:submit="save" class="rounded-xl bg-white shadow-sm ring-1 ring-chrome-900/5">
    <div class="flex h-12 items-center justify-between border-b border-chrome-200 px-4">
        <div class="flex items-center gap-3">
            <h2 class="text-sm font-semibold text-chrome-800">{{ $title ?: ($record->exists ? 'Edit' : 'New') }}</h2>

            {{-- Odoo-style record navigation. Only on saved records (a
                 brand-new form has no neighbours); a disabled chevron
                 keeps the layout stable when you hit the first or last
                 record. Chevrons stay physically left/right regardless
                 of locale — Odoo's Arabic UI keeps the same visual. --}}
            @if ($record->exists)
                <div class="flex items-center gap-0.5">
                    @if ($prevId !== null)
                        <a href="{{ $this->navUrl($prevId) }}" wire:navigate
                            aria-label="{{ __('Previous record') }}" title="{{ __('Previous record') }}"
                            class="flex size-7 items-center justify-center rounded-md text-chrome-500 hover:bg-chrome-100 hover:text-chrome-800">
                            <svg class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fill-rule="evenodd" d="M12.78 5.22a.75.75 0 0 1 0 1.06L9.06 10l3.72 3.72a.75.75 0 1 1-1.06 1.06l-4.25-4.25a.75.75 0 0 1 0-1.06l4.25-4.25a.75.75 0 0 1 1.06 0Z" clip-rule="evenodd"/>
                            </svg>
                        </a>
                    @else
                        <span aria-label="{{ __('Previous record') }}" aria-disabled="true"
                            class="flex size-7 cursor-not-allowed items-center justify-center rounded-md text-chrome-300">
                            <svg class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fill-rule="evenodd" d="M12.78 5.22a.75.75 0 0 1 0 1.06L9.06 10l3.72 3.72a.75.75 0 1 1-1.06 1.06l-4.25-4.25a.75.75 0 0 1 0-1.06l4.25-4.25a.75.75 0 0 1 1.06 0Z" clip-rule="evenodd"/>
                            </svg>
                        </span>
                    @endif

                    @if ($nextId !== null)
                        <a href="{{ $this->navUrl($nextId) }}" wire:navigate
                            aria-label="{{ __('Next record') }}" title="{{ __('Next record') }}"
                            class="flex size-7 items-center justify-center rounded-md text-chrome-500 hover:bg-chrome-100 hover:text-chrome-800">
                            <svg class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fill-rule="evenodd" d="M7.22 14.78a.75.75 0 0 1 0-1.06L10.94 10 7.22 6.28a.75.75 0 0 1 1.06-1.06l4.25 4.25a.75.75 0 0 1 0 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0Z" clip-rule="evenodd"/>
                            </svg>
                        </a>
                    @else
                        <span aria-label="{{ __('Next record') }}" aria-disabled="true"
                            class="flex size-7 cursor-not-allowed items-center justify-center rounded-md text-chrome-300">
                            <svg class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fill-rule="evenodd" d="M7.22 14.78a.75.75 0 0 1 0-1.06L10.94 10 7.22 6.28a.75.75 0 0 1 1.06-1.06l4.25 4.25a.75.75 0 0 1 0 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0Z" clip-rule="evenodd"/>
                            </svg>
                        </span>
                    @endif
                </div>
            @endif
        </div>
        <div class="flex gap-2">
            @if ($record->exists)
                {{-- Auto-save status pill. Driven by Livewire's commit
                     lifecycle hook so the state cleanly flips between
                     "Saving…" (request in flight) and "Saved" (idle / last
                     request succeeded). Earlier we tried `wire:loading`
                     directives but they don't reliably match wire:model.live
                     commits (those target the property, not the autoSave
                     method) — both branches ended up visible at the same
                     time. The hook is scoped to THIS component id so other
                     Livewire activity on the page can't trigger a flicker. --}}
                <div class="flex items-center gap-1.5 text-xs font-medium"
                     x-data="{
                        state: 'saved',
                        init() {
                            const id = this.$root.closest('[wire\\:id]')?.getAttribute('wire:id');
                            if (!id) return;
                            Livewire.hook('commit', ({ component, succeed, fail }) => {
                                if (component.id !== id) return;
                                this.state = 'saving';
                                succeed(() => { this.state = 'saved'; });
                                fail(() => { this.state = 'error'; });
                            });
                        }
                     }">
                    <span x-show="state === 'saving'" class="flex items-center gap-1 text-chrome-400">
                        <svg class="size-3.5 animate-spin" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                            <path d="M10 3a7 7 0 1 0 7 7" stroke-linecap="round"/>
                        </svg>
                        {{ __('Saving…') }}
                    </span>
                    <span x-show="state === 'saved'" class="flex items-center gap-1 text-emerald-600">
                        <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M16.704 5.296a1 1 0 0 1 0 1.408l-7.5 7.5a1 1 0 0 1-1.408 0l-3.5-3.5a1 1 0 0 1 1.408-1.408L8.5 12.09l6.796-6.795a1 1 0 0 1 1.408 0Z" clip-rule="evenodd"/>
                        </svg>
                        {{ __('Saved') }}
                    </span>
                    <span x-show="state === 'error'" class="flex items-center gap-1 text-red-600">
                        <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm-.75-5.75a.75.75 0 0 0 1.5 0v-4.5a.75.75 0 0 0-1.5 0v4.5Zm.75 2.25a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" clip-rule="evenodd"/>
                        </svg>
                        {{ __('Not saved') }}
                    </span>
                </div>
            @else
                {{-- `wire:loading.attr="disabled"` blocks a rapid second
                     click on the Save button while the create request is
                     in flight — without it, two near-simultaneous submits
                     can each go through and create duplicate records.
                     `wire:target="save"` scopes it to the save action so
                     a translatable-pill click can't accidentally disable
                     the button. After the first save, the page navigates
                     to the canonical edit URL and the button is replaced
                     by the status pill, so no further double-click risk. --}}
                <button type="submit"
                        wire:loading.attr="disabled" wire:target="save"
                        class="o-btn-primary disabled:cursor-not-allowed disabled:opacity-60">{{ __('Save') }}</button>
            @endif
        </div>
    </div>

    <div class="grid grid-cols-1 {{ $gridCols }} gap-4 p-6">
        @foreach ($fields as $field)
            @php
                $key = 'form.' . $field->field;
                $full = in_array($field->widget, ['textarea', 'image'], true);
            @endphp
            <div class="{{ $full ? 'sm:col-span-2' : '' }}">
                <div class="mb-1 flex items-center justify-between gap-2">
                    <label class="block text-xs font-semibold uppercase tracking-wide text-chrome-500">
                        {{ $field->label }}
                        @if ($field->required) <span class="text-red-500">*</span> @endif
                    </label>
                    @if ($field->isTranslatable())
                        {{-- Odoo-style language pills. Active pill = solid purple
                             (the locale being edited right now); others are light
                             grey. Clicking calls `switchLocale($field, $locale)`
                             on the component, which buffers the current edit and
                             swaps the input to that locale's text. The buffer
                             holds ALL locales across switches so a click never
                             loses a value. --}}
                        @php $activeLocale = $translationLocale[$field->field] ?? 'en'; @endphp
                        <div class="flex items-center gap-1" wire:key="loc-{{ $field->field }}">
                            @foreach ($locales as $loc)
                                <button type="button"
                                    wire:click="switchLocale('{{ $field->field }}', '{{ $loc }}')"
                                    class="rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wider transition-colors {{ $activeLocale === $loc ? 'bg-primary-600 text-white' : 'bg-chrome-100 text-chrome-500 hover:bg-chrome-200' }}">
                                    {{ $loc }}
                                </button>
                            @endforeach
                        </div>
                    @endif
                </div>

                @switch($field->widget)
                    @case('textarea')
                        {{-- `.live.debounce.500ms` so each pause-while-typing
                             triggers updated() → autoSave() once the record
                             exists. New records bypass auto-save and rely on
                             the Save button (record needs an id first). --}}
                        <textarea wire:model.live.debounce.500ms="{{ $key }}" rows="3"
                            placeholder="{{ $field->placeholder }}" class="o-input resize-none"></textarea>
                        @break

                    @case('checkbox')
                        {{-- Boolean — no debounce, the click IS the commit. --}}
                        <label class="inline-flex items-center gap-2">
                            <input type="checkbox" wire:model.live="{{ $key }}"
                                class="rounded border-chrome-300 text-primary-600 focus:ring-primary-500">
                            <span class="text-sm text-chrome-600">{{ $field->placeholder ?: 'Yes' }}</span>
                        </label>
                        @break

                    @case('color')
                        {{-- Clickable swatch (opens the OS colour picker) kept in
                             sync with a hex text field, so a colour can be picked
                             visually OR typed. Both push to the same wire model;
                             the swatch uses @change (one commit per pick, not per
                             drag frame), the text field is debounced. The leading
                             preview dot mirrors whatever's currently valid. --}}
                        @php $colorVal = (string) ($record->getAttribute($field->field) ?? ''); @endphp
                        <div class="flex items-center gap-2"
                            x-data="{
                                color: @js($colorVal),
                                get swatch() {
                                    return /^#[0-9a-fA-F]{6}$/.test(this.color) ? this.color : '{{ '#714b67' }}';
                                },
                                sync(v) { this.color = v; $wire.set('{{ $key }}', v); },
                            }">
                            <input type="color" :value="swatch"
                                @change="sync($event.target.value)"
                                aria-label="{{ $field->label }}"
                                class="size-9 shrink-0 cursor-pointer rounded-md border border-chrome-300 bg-white p-1">
                            <input type="text" :value="color" maxlength="7"
                                @input.debounce.400ms="sync($event.target.value)"
                                placeholder="{{ $field->placeholder }}"
                                class="o-input max-w-[8rem] font-mono uppercase">
                        </div>
                        @break

                    @case('select')
                        {{-- Picking is an atomic action — auto-save instantly. --}}
                        <select wire:model.live="{{ $key }}" class="o-input">
                            <option value="">—</option>
                            @foreach (($options[$field->field] ?? $field->options) as $opt)
                                <option value="{{ $opt['value'] }}">{{ $opt['label'] }}</option>
                            @endforeach
                        </select>
                        @break

                    @case('image')
                        {{-- Direct synchronous upload via FormImageUploadController.
                             Replaced Livewire's two-phase signed-URL upload because
                             that pipeline silently fails on Hostinger shared hosting
                             (mod_security blocks the multipart POST to /livewire/upload-file).
                             Alpine reads the picked file, POSTs it straight to our own
                             controller, gets back a stored path, writes it onto the
                             component via $wire.set('imagePaths.<field>', path). --}}
                        @php
                            $current = $record->getAttribute($field->field);
                            $bucket = $record->getTable();
                            $previewUrl = $current
                                ? \Illuminate\Support\Facades\Storage::disk('public')->url($current)
                                : '';
                        @endphp
                        <div class="flex items-center gap-4"
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
                                     data.append('bucket', @js($bucket));
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
                                         await $wire.set(@js('imagePaths.' . $field->field), j.path);
                                     } catch (err) {
                                         this.error = err.message || (@js(__('Upload failed.')));
                                     } finally {
                                         this.busy = false;
                                     }
                                 },
                             }">
                            <span class="flex size-16 items-center justify-center overflow-hidden rounded-full bg-chrome-100 text-chrome-400">
                                <template x-if="previewUrl">
                                    <img :src="previewUrl" class="size-full object-cover">
                                </template>
                                <template x-if="!previewUrl">
                                    <svg class="size-7" viewBox="0 0 20 20" fill="currentColor"><path d="M10 10a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm0 2c-3 0-7 1.6-7 4v2h14v-2c0-2.4-4-4-7-4Z"/></svg>
                                </template>
                            </span>
                            <div class="flex flex-col gap-1">
                                <input type="file" accept="image/*" @change="upload($event)"
                                    class="text-sm text-chrome-600 file:mr-3 file:rounded-md file:border-0 file:bg-chrome-100 file:px-3 file:py-1.5 file:text-sm">
                                <p class="text-xs text-chrome-400">{{ __('Accepted: JPG, PNG, GIF, WebP, AVIF, HEIC, BMP · max 4 MB') }}</p>
                                <p x-show="busy" class="text-xs text-chrome-400">{{ __('Uploading…') }}</p>
                                <p x-show="error" x-text="error" class="text-xs text-red-600"></p>
                            </div>
                        </div>
                        @break

                    @default
                        {{-- `step="any"` on number inputs accepts decimals without
                             pinning a fixed precision — currencies are 2-decimal
                             across the board now, but a future 3-decimal opt-in
                             shouldn't force this Blade to change. Browsers
                             default to step="1" on <input type=number>, which is
                             what kicked out "8.5" with "two nearest valid values
                             are 8 and 9". Harmless on non-number widgets.
                             `.live.debounce.500ms` powers auto-save: each pause
                             after a keystroke syncs the value to the server
                             and runs autoSave() once the record exists. --}}
                        <input type="{{ $field->widget === 'datetime' ? 'datetime-local' : $field->widget }}"
                            @if ($field->widget === 'number') step="any" @endif
                            wire:model.live.debounce.500ms="{{ $key }}" placeholder="{{ $field->placeholder }}" class="o-input">
                @endswitch

                @error($key) <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                @error('uploads.' . $field->field) <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                @if ($field->help) <p class="mt-1 text-xs text-chrome-400">{{ $field->help }}</p> @endif
            </div>
        @endforeach
    </div>
</form>
