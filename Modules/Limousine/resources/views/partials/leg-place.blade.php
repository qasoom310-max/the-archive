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
{{-- On a phone the country and the place each take a full row (like the map
     link under them); from sm up they share one. --}}
<div class="flex flex-col gap-1 sm:flex-row sm:gap-2">
    <select wire:model.live="legs.{{ $i }}.{{ $side }}_country" class="o-input w-full sm:w-40 sm:shrink-0"
            aria-label="{{ $side === 'from' ? __('Pick-up country') : __('Drop-off country') }}">
        @foreach ($countryOptions as $opt)
            <option value="{{ $opt['value'] }}">{{ __($opt['label']) }}</option>
        @endforeach
    </select>
    {{-- Keyed by the country so a new country starts a fresh "is it on the
         list" check rather than reading the old country's list.
         The places are suggested in our own small list, not the browser's: a
         datalist pops up on every key press (on a phone as a black bubble
         over the box). This one opens only once typing has paused, and only
         for two letters or more. --}}
    <div class="relative min-w-0 flex-1" wire:key="leg-{{ $i }}-{{ $side }}-place-{{ $country }}"
         x-data="{
            v: @js($leg[$side.'_location'] ?? ''),
            known: @js($known),
            places: @js(array_values($locationsByCountry[$country] ?? [])),
            open: false, hits: [], active: -1, timer: null,
            typed(value) {
                this.v = value;
                this.close();
                this.timer = setTimeout(() => this.suggest(), 800);
            },
            suggest() {
                const q = this.v.trim().toLowerCase();
                const starts = [], has = [];
                if (q.length >= 2) {
                    for (const p of this.places) {
                        const l = p.toLowerCase();
                        if (l === q) { continue; }
                        if (l.startsWith(q)) { starts.push(p); } else if (l.includes(q)) { has.push(p); }
                    }
                }
                this.hits = starts.concat(has).slice(0, 8);
                this.open = this.hits.length > 0;
            },
            close() { clearTimeout(this.timer); this.open = false; this.active = -1; },
            pick(name) {
                this.close();
                this.v = name;
                this.$refs.box.value = name;
                this.$wire.set('legs.{{ $i }}.{{ $side }}_location', name, false);
            },
            move(step) {
                if (this.open) { this.active = (this.active + step + this.hits.length) % this.hits.length; }
            },
         }"
         x-on:click.outside="close()">
        <input type="text" x-ref="box" autocomplete="off" wire:model="legs.{{ $i }}.{{ $side }}_location"
               x-on:input="typed($event.target.value)"
               x-on:blur="setTimeout(() => close(), 150)"
               x-on:keydown.escape="close()"
               x-on:keydown.arrow-down.prevent="move(1)"
               x-on:keydown.arrow-up.prevent="move(-1)"
               x-on:keydown.enter="if (open && active >= 0) { $event.preventDefault(); pick(hits[active]); }"
               class="o-input w-full" placeholder="{{ $placeholder }}" data-place-box="{{ $country }}">
        <ul x-cloak x-show="open" role="listbox"
            class="absolute start-0 end-0 top-full z-30 mt-1 max-h-60 overflow-y-auto rounded-md border border-chrome-200 bg-white py-1 text-sm shadow-lg">
            <template x-for="(name, n) in hits" :key="name">
                <li role="option" x-text="name"
                    x-on:mousedown.prevent="pick(name)"
                    x-on:mouseenter="active = n"
                    :class="n === active ? 'bg-primary-100 text-chrome-900' : 'text-chrome-700'"
                    class="cursor-pointer px-3 py-2"></li>
            </template>
        </ul>
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
