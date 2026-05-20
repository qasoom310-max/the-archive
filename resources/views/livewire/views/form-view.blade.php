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
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">
                    {{ $field->label }}
                    @if ($field->required) <span class="text-red-500">*</span> @endif
                </label>

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
                                    <img src="{{ $uploads[$field->field]->temporaryUrl() }}" class="size-full object-cover">
                                @elseif ($current)
                                    <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($current) }}" class="size-full object-cover">
                                @else
                                    <svg class="size-7" viewBox="0 0 20 20" fill="currentColor"><path d="M10 10a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm0 2c-3 0-7 1.6-7 4v2h14v-2c0-2.4-4-4-7-4Z"/></svg>
                                @endif
                            </span>
                            <input type="file" wire:model="uploads.{{ $field->field }}" accept="image/*"
                                class="text-sm text-chrome-600 file:mr-3 file:rounded-md file:border-0 file:bg-chrome-100 file:px-3 file:py-1.5 file:text-sm">
                        </div>
                        <div wire:loading wire:target="uploads.{{ $field->field }}" class="mt-1 text-xs text-chrome-400">Uploading…</div>
                        @break

                    @default
                        <input type="{{ $field->widget === 'datetime' ? 'datetime-local' : $field->widget }}"
                            wire:model="{{ $key }}" placeholder="{{ $field->placeholder }}" class="o-input">
                @endswitch

                @error($key) <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                @error('uploads.' . $field->field) <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                @if ($field->help) <p class="mt-1 text-xs text-chrome-400">{{ $field->help }}</p> @endif
            </div>
        @endforeach
    </div>
</form>
