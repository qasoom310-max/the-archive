{{--
    A date box that always reads day/month/year.

    Drop-in for a native date / datetime-local input: pass the
    same wire:model and the field behaves exactly as it did, because the real
    input is still here carrying the binding and its ISO value. This only draws
    a text box over it that we format ourselves (see the `dateField` Alpine
    component) — a native date input renders in the BROWSER's locale, and no
    attribute on the page overrides that.

        <x-date-field wire:model="quote_date" class="o-input w-full" />
        <x-date-field type="datetime-local" wire:model="legs.0.start_at" />

    Typing is forgiving — 31/08/2026, 31-08-2026, 31.8.26 — and the calendar
    button still opens the browser's own picker.
--}}
@props(['type' => 'date'])

@php
    $withTime = $type === 'datetime-local';

    // The caller's classes describe the visible control, which here is the
    // wrapper rather than the input hidden behind it. `border px-3 py-2`
    // because @tailwindcss/forms styles real form controls, not a div.
    $control = $attributes->get('class', 'o-input w-full');
    $locked = (bool) $attributes->get('disabled', false);
    $bound = $attributes->except(['class', 'disabled', 'type']);
@endphp

<div x-data="dateField('{{ $type }}')"
     class="date-field {{ $control }} relative flex items-center gap-2 border px-3 py-2 {{ $locked ? 'cursor-not-allowed bg-chrome-100 text-chrome-500' : '' }}">

    {{-- The real control. Livewire binds to THIS, so every modifier keeps
         working and validation still points at the same field. On desktop it is
         laid out behind the text box (so the browser picker has somewhere to
         open); on touch devices (`pointer: coarse`, see app.css) it becomes the
         visible, tappable control and the OS date/time picker is used directly
         — far more reliable and familiar on a phone than a scripted picker. --}}
    <input type="{{ $type }}" x-ref="native" {{ $bound }} @disabled($locked)
           class="date-field-native pointer-events-none absolute inset-0 h-full w-full opacity-0"
           tabindex="-1" aria-hidden="true">

    <input type="text" x-ref="text" x-model="display"
           x-on:change="commit()" x-on:blur="commit()"
           x-on:keydown.enter.prevent="commit()"
           @disabled($locked)
           placeholder="{{ $withTime ? __('dd/mm/yyyy hh:mm') : __('dd/mm/yyyy') }}"
           class="date-field-text w-full border-0 bg-transparent p-0 text-sm text-inherit placeholder:text-chrome-400 focus:ring-0 disabled:cursor-not-allowed">

    @unless ($locked)
        <button type="button" x-on:click="pick()" tabindex="-1"
                title="{{ __('Open the calendar') }}" aria-label="{{ __('Open the calendar') }}"
                class="date-field-cal relative z-10 shrink-0 text-chrome-400 transition hover:text-chrome-700">
            <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5"/>
            </svg>
        </button>
    @endunless
</div>
