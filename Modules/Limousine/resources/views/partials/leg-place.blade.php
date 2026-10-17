{{-- One side (From / To) of a trip leg: the country first, then the place in
     that country. A place not on the list can be added to it with the button
     under the box. Expects: $i, $leg, $side ('from'|'to'), $label,
     $placeholder, $countryOptions, $locationsByCountry, $canAddLocation. --}}
@php
    $country = $leg[$side.'_country'] ?? \Modules\Limousine\Models\LimoLocation::DEFAULT_COUNTRY;
    $known = array_map('mb_strtolower', $locationsByCountry[$country] ?? []);
    $countryName = collect($countryOptions)->firstWhere('value', $country)['label'] ?? $country;
@endphp
<label class="mb-1 block text-sm font-medium text-chrome-700">{{ $label }} *</label>
<div class="flex gap-2">
    <select wire:model.live="legs.{{ $i }}.{{ $side }}_country" class="o-input w-32 shrink-0 sm:w-40"
            aria-label="{{ $side === 'from' ? __('Pick-up country') : __('Drop-off country') }}">
        @foreach ($countryOptions as $opt)
            <option value="{{ $opt['value'] }}">{{ __($opt['label']) }}</option>
        @endforeach
    </select>
    {{-- Keyed by the country so a new country starts a fresh "is it on the
         list" check rather than reading the old country's list. --}}
    <div class="min-w-0 flex-1" wire:key="leg-{{ $i }}-{{ $side }}-place-{{ $country }}"
         x-data="{ v: @js($leg[$side.'_location'] ?? ''), known: @js($known) }">
        <input type="text" list="limo-locations-{{ $country }}" wire:model="legs.{{ $i }}.{{ $side }}_location"
               x-on:input="v = $event.target.value" class="o-input w-full" placeholder="{{ $placeholder }}">
        @if ($canAddLocation)
            <button type="button" x-cloak
                    x-show="v.trim() !== '' && ! known.includes(v.trim().toLowerCase())"
                    x-on:click="known.push(v.trim().toLowerCase()); $wire.addLegLocation({{ $i }}, '{{ $side }}', v)"
                    class="mt-1 inline-flex items-center gap-1 text-xs font-medium text-primary-700 hover:underline">
                <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor"><path d="M10.75 4.75a.75.75 0 0 0-1.5 0v4.5h-4.5a.75.75 0 0 0 0 1.5h4.5v4.5a.75.75 0 0 0 1.5 0v-4.5h4.5a.75.75 0 0 0 0-1.5h-4.5v-4.5Z"/></svg>
                {{ __('Add') }} “<span x-text="v.trim()"></span>” {{ __('to :country', ['country' => __($countryName)]) }}
            </button>
        @endif
    </div>
</div>
@error('legs.'.$i.'.'.$side.'_country') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
@error('legs.'.$i.'.'.$side.'_location') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
