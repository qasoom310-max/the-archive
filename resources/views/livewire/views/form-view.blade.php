@php
    $gridCols = $cols >= 2 ? 'sm:grid-cols-2' : 'grid-cols-1';
@endphp

<form wire:submit="save" class="rounded-xl bg-white shadow-sm ring-1 ring-chrome-900/5">
    <div class="flex h-12 items-center justify-between border-b border-chrome-200 px-4">
        <h2 class="text-sm font-semibold text-chrome-800">{{ $title ?: ($record->exists ? 'Edit' : 'New') }}</h2>
        <div class="flex gap-2">
            <button type="submit" class="o-btn-primary">Save</button>
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
                        <textarea wire:model="{{ $key }}" rows="3"
                            placeholder="{{ $field->placeholder }}" class="o-input resize-none"></textarea>
                        @break

                    @case('checkbox')
                        <label class="inline-flex items-center gap-2">
                            <input type="checkbox" wire:model="{{ $key }}"
                                class="rounded border-chrome-300 text-primary-600 focus:ring-primary-500">
                            <span class="text-sm text-chrome-600">{{ $field->placeholder ?: 'Yes' }}</span>
                        </label>
                        @break

                    @case('select')
                        <select wire:model="{{ $key }}" class="o-input">
                            <option value="">—</option>
                            @foreach (($options[$field->field] ?? $field->options) as $opt)
                                <option value="{{ $opt['value'] }}">{{ $opt['label'] }}</option>
                            @endforeach
                        </select>
                        @break

                    @case('image')
                        <div class="flex items-center gap-4">
                            @php $current = $record->getAttribute($field->field); @endphp
                            <span class="flex size-16 items-center justify-center overflow-hidden rounded-full bg-chrome-100 text-chrome-400">
                                @if (isset($uploads[$field->field]) && $uploads[$field->field])
                                    {{-- Livewire's temporaryUrl() throws for any extension not in
                                         livewire.temporary_file_upload.preview_mimes (config/livewire.php).
                                         Guard so a non-previewable type (e.g. HEIC) renders a placeholder
                                         instead of 500ing the whole form. --}}
                                    @if ($uploads[$field->field]->isPreviewable())
                                        <img src="{{ $uploads[$field->field]->temporaryUrl() }}" class="size-full object-cover">
                                    @else
                                        <svg class="size-7" viewBox="0 0 20 20" fill="currentColor"><path d="M4 4h12v12H4V4Zm2 2v8h8V6H6Zm2 2h4v4H8V8Z"/></svg>
                                    @endif
                                @elseif ($current)
                                    <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($current) }}" class="size-full object-cover">
                                @else
                                    <svg class="size-7" viewBox="0 0 20 20" fill="currentColor"><path d="M10 10a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm0 2c-3 0-7 1.6-7 4v2h14v-2c0-2.4-4-4-7-4Z"/></svg>
                                @endif
                            </span>
                            <div class="flex flex-col gap-1">
                                <input type="file" wire:model="uploads.{{ $field->field }}" accept="image/*"
                                    class="text-sm text-chrome-600 file:mr-3 file:rounded-md file:border-0 file:bg-chrome-100 file:px-3 file:py-1.5 file:text-sm">
                                <p class="text-xs text-chrome-400">{{ __('Accepted: JPG, PNG, GIF, WebP, AVIF, HEIC, SVG, BMP · max 2 MB') }}</p>
                            </div>
                        </div>
                        <div wire:loading wire:target="uploads.{{ $field->field }}" class="mt-1 text-xs text-chrome-400">{{ __('Uploading…') }}</div>
                        @break

                    @default
                        {{-- `step="any"` on number inputs accepts decimals without
                             pinning a fixed precision — important now currencies
                             can be 2- or 3-decimal (BHD/KWD/OMR etc.). Browsers
                             default to step="1" on <input type=number>, which is
                             what kicked out "8.5" with "two nearest valid values
                             are 8 and 9". Harmless on non-number widgets. --}}
                        <input type="{{ $field->widget === 'datetime' ? 'datetime-local' : $field->widget }}"
                            @if ($field->widget === 'number') step="any" @endif
                            wire:model="{{ $key }}" placeholder="{{ $field->placeholder }}" class="o-input">
                @endswitch

                @error($key) <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                @error('uploads.' . $field->field) <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                @if ($field->help) <p class="mt-1 text-xs text-chrome-400">{{ $field->help }}</p> @endif
            </div>
        @endforeach
    </div>
</form>
