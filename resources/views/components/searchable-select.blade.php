{{--
    A <select> you can type into.

    Drop-in for a long <select>: pass the same options and the same wire:model,
    and the field behaves as it always did — because the real control is still
    here, carrying the binding. This only draws a button and a filtered list over
    it (see the `searchableSelect` Alpine component).

    Use it where the list can GROW — customers, cars, drivers, accounts. A select
    of three fixed choices is quicker left alone; a search box over it is just
    another thing to dismiss.

        <x-searchable-select wire:model.live="customer_id" :options="$customers" />

    Options are `[['value' => …, 'label' => …], …]`, the shape the option lists
    in this app already use.
--}}
@props([
    'options' => [],
    'placeholder' => null,
    'empty' => null,
    'searchPlaceholder' => null,
])

@php
    $placeholder ??= __('— Select —');
    $empty ??= __('Nothing to choose from');
    $searchPlaceholder ??= __('Type to search…');

    // The caller's classes describe the visible control, which here is the
    // button rather than the select hidden behind it.
    $control = $attributes->get('class', 'o-input w-full');

    // Pulled out of the bag and applied to BOTH: a disabled select with a live
    // button would look locked and open anyway.
    $locked = (bool) $attributes->get('disabled', false);
    $bound = $attributes->except(['class', 'disabled']);

    // Which property this field is bound to ("customer_id", "legs.0.car_id").
    // The button reads the chosen name out of the component's own state, not
    // just off the control — Livewire fills a control AFTER Alpine has looked
    // at it. See `searchableSelect` in resources/js/app.js.
    $model = (string) ($attributes->wire('model')->value() ?: '');

    // …and mark the chosen option here, so the name is right in the HTML the
    // browser first paints rather than a moment later.
    $selected = null;
    if ($model !== '') {
        try {
            $component = \Livewire\Livewire::current();
            $selected = $component !== null ? data_get($component->all(), $model) : null;
        } catch (\Throwable) {
            $selected = null; // Not inside a Livewire component: the JS covers it.
        }
    }
    $selected = $selected === null ? '' : (string) $selected;
@endphp

<div x-data="searchableSelect(@js($model))" class="relative"
     x-on:keydown.escape.prevent.stop="close()"
     x-on:click.outside="close()">

    {{-- The real control. Livewire binds to THIS, so every modifier keeps
         working and validation still points at the same field. Hidden from
         sight and from assistive tech, which get the combobox below instead. --}}
    <select x-ref="native" {{ $bound }} @disabled($locked)
            class="sr-only" tabindex="-1" aria-hidden="true">
        <option value="">{{ $placeholder }}</option>
        @foreach ($options as $option)
            <option value="{{ $option['value'] }}"{{ $selected !== '' && (string) $option['value'] === $selected ? ' selected' : '' }}>{{ $option['label'] }}</option>
        @endforeach
    </select>

    {{-- `border px-3 py-2` on purpose: the caller's `o-input` sets the border
         COLOUR, the rounding and the background, but the WIDTH and padding come
         from @tailwindcss/forms, which styles real form controls — an input, a
         select — and not a button. Without them this drew no box at all and the
         field read as a stray line of text among bordered ones. --}}
    <button type="button" x-ref="button" x-on:click="toggle()"
            x-on:keydown.down.prevent="show()"
            @disabled($locked)
            role="combobox" aria-haspopup="listbox"
            :aria-expanded="open ? 'true' : 'false'"
            class="{{ $control }} flex items-center justify-between gap-2 border px-3 py-2 text-start disabled:cursor-not-allowed disabled:bg-chrome-100 disabled:text-chrome-500">
        <span class="truncate" :class="label === '' && 'text-chrome-400'"
              x-text="label === '' ? @js($placeholder) : label"></span>
        <svg class="size-4 shrink-0 text-chrome-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path fill-rule="evenodd" d="M10 15a1 1 0 0 1-.71-.29l-5-5a1 1 0 1 1 1.42-1.42L10 12.59l4.29-4.3a1 1 0 1 1 1.42 1.42l-5 5A1 1 0 0 1 10 15Z" clip-rule="evenodd"/>
        </svg>
    </button>

    <div x-show="open" x-cloak x-transition.opacity.duration.100ms
         class="absolute z-40 mt-1 w-full overflow-hidden rounded-xl bg-white shadow-pop ring-1 ring-chrome-200">

        <div class="border-b border-chrome-100 p-2">
            <input type="text" x-ref="search" x-model="query"
                   x-on:keydown.down.prevent="move(1)"
                   x-on:keydown.up.prevent="move(-1)"
                   x-on:keydown.enter.prevent="choose()"
                   x-on:keydown.tab="close()"
                   class="o-input w-full text-sm"
                   placeholder="{{ $searchPlaceholder }}">
        </div>

        <ul x-ref="list" role="listbox" class="max-h-64 overflow-y-auto py-1 text-sm">
            {{-- Clearing is a choice too, and there is nothing to search for to
                 reach it. Hidden once a search narrows the list, where it would
                 only be in the way. --}}
            <template x-if="query.trim() === '' && label !== ''">
                <li>
                    <button type="button" x-on:click="clear()"
                            class="block w-full px-3 py-2 text-start text-chrome-500 hover:bg-chrome-50">
                        {{ $placeholder }}
                    </button>
                </li>
            </template>

            <template x-for="(option, index) in matches" :key="option.value">
                <li>
                    <button type="button"
                            x-on:click="pick(option.value)"
                            x-on:mouseenter="active = index"
                            role="option"
                            :aria-selected="option.label === label"
                            :data-active="active === index"
                            class="block w-full truncate px-3 py-2 text-start text-chrome-700"
                            :class="{
                                'bg-chrome-200': active === index,
                                'font-semibold': option.label === label,
                            }"
                            x-text="option.label"></button>
                </li>
            </template>

            <template x-if="matches.length === 0">
                <li class="px-3 py-6 text-center text-chrome-400">
                    <span x-text="query.trim() === '' ? @js($empty) : @js(__('No match'))"></span>
                </li>
            </template>
        </ul>
    </div>
</div>
