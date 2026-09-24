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
    button opens the browser's own picker.

    Pass `calendar` and it draws its OWN month grid instead, on every device:

        <x-date-field type="datetime-local" calendar wire:model="legs.0.start_at" />

    Use that where the WEEKDAY matters while choosing — a phone hands an
    ordinary date field to the operating system, and the OS wheel shows a bare
    number, so "the 24th" cannot be seen to be the Thursday that was asked
    for. Everywhere else the OS picker is the better control and stays.
--}}
@props(['type' => 'date', 'calendar' => false])

@php
    $withTime = $type === 'datetime-local';

    // Opt-in month grid (see the `calendar` prop). A phone hands the field to
    // the OS, whose date WHEEL never says which weekday a number is — the one
    // thing a dispatcher needs to see while choosing. Our own grid shows the
    // weekday over every column and opens on the month already chosen.
    //
    // Names come from PHP, not the browser: the app's locale is a server-side
    // setting, and a phone set to another language would otherwise print its
    // own month names inside our Arabic page.
    $weekdayNames = [];
    $monthNames = [];
    $timeId = uniqid('df-time-');
    if ($calendar) {
        $sunday = \Carbon\CarbonImmutable::now()->startOfWeek(\Carbon\Carbon::SUNDAY);
        for ($i = 0; $i < 7; $i++) {
            $weekdayNames[] = $sunday->addDays($i)->locale(app()->getLocale())->isoFormat('ddd');
        }
        for ($m = 1; $m <= 12; $m++) {
            $monthNames[] = \Carbon\CarbonImmutable::create(2026, $m, 1)?->locale(app()->getLocale())->isoFormat('MMMM');
        }
    }

    // The caller's classes describe the visible control, which here is the
    // wrapper rather than the input hidden behind it. `border px-3 py-2`
    // because @tailwindcss/forms styles real form controls, not a div.
    $control = $attributes->get('class', 'o-input w-full');
    $locked = (bool) $attributes->get('disabled', false);
    $bound = $attributes->except(['class', 'disabled', 'type']);
@endphp

<div x-data="dateField('{{ $type }}', @js($calendar), @js($weekdayNames), @js($monthNames))"
     @if ($calendar)
         x-on:keydown.escape.stop="closeCalendar()"
         x-on:click.outside="closeCalendar()"
     @endif
     class="date-field {{ $calendar ? 'date-field-calendar' : '' }} {{ $control }} relative flex items-center gap-2 border px-3 py-2 {{ $locked ? 'cursor-not-allowed bg-chrome-100 text-chrome-500' : '' }}">

    {{-- The real control. Livewire binds to THIS, so every modifier keeps
         working and validation still points at the same field. On desktop it is
         laid out behind the text box (so the browser picker has somewhere to
         open); on touch devices (`pointer: coarse`, see app.css) it becomes the
         visible, tappable control and the OS date/time picker is used directly
         — far more reliable and familiar on a phone than a scripted picker. --}}
    <input type="{{ $type }}" x-ref="native" {{ $bound }} @disabled($locked)
           class="date-field-native pointer-events-none absolute inset-0 h-full w-full opacity-0"
           tabindex="-1" aria-hidden="true">

    {{-- With the grid on, a TAP opens it rather than the phone's keyboard —
         readonly only on a touch screen, so a desk keyboard still types. --}}
    <input type="text" x-ref="text" x-model="display"
           x-on:change="commit()" x-on:blur="commit()"
           x-on:keydown.enter.prevent="commit()"
           @if ($calendar)
               x-on:click="if (touch) openCalendar()"
               :readonly="touch"
           @endif
           @disabled($locked)
           placeholder="{{ $withTime ? __('dd/mm/yyyy hh:mm') : __('dd/mm/yyyy') }}"
           class="date-field-text w-full border-0 bg-transparent p-0 text-sm text-inherit placeholder:text-chrome-400 focus:ring-0 disabled:cursor-not-allowed">

    @unless ($locked)
        <button type="button" x-on:click="{{ $calendar ? 'toggleCalendar()' : 'pick()' }}" tabindex="-1"
                title="{{ __('Open the calendar') }}" aria-label="{{ __('Open the calendar') }}"
                class="date-field-cal relative z-10 shrink-0 text-chrome-400 transition hover:text-chrome-700">
            <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5"/>
            </svg>
        </button>
    @endunless

    @if ($calendar && ! $locked)
        {{-- dir="ltr" is NOT set: a grid of weekdays is read in the page's own
             direction, and Arabic calendars run right to left. `grid-cols-7`
             mirrors on its own under dir="rtl", columns and headings together. --}}
        <div x-show="calendarOpen" x-cloak x-transition.opacity.duration.100ms
             class="absolute top-full z-40 mt-1 w-[19rem] rounded-xl bg-white p-3 shadow-pop ring-1 ring-chrome-200 start-0">

            <div class="flex items-center justify-between">
                <button type="button" x-on:click="shiftMonth(-1)"
                        class="rounded-lg px-2 py-1 text-chrome-500 transition hover:bg-chrome-100"
                        aria-label="{{ __('Previous month') }}">&lsaquo;</button>
                <p class="text-sm font-semibold text-chrome-800" x-text="monthLabel"></p>
                <button type="button" x-on:click="shiftMonth(1)"
                        class="rounded-lg px-2 py-1 text-chrome-500 transition hover:bg-chrome-100"
                        aria-label="{{ __('Next month') }}">&rsaquo;</button>
            </div>

            <div class="mt-2 grid grid-cols-7 gap-0.5 text-center text-[11px] font-semibold uppercase text-chrome-400">
                <template x-for="(name, w) in weekdayNames" :key="w">
                    <span x-text="name"></span>
                </template>
            </div>

            <div class="mt-1 grid grid-cols-7 gap-0.5 text-center text-sm">
                <template x-for="(day, index) in monthGrid()" :key="index">
                    <div>
                        <button type="button" x-show="day !== null" x-on:click="choose(day)"
                                class="w-full rounded-lg py-1.5 transition"
                                :class="isChosen(day)
                                    ? 'bg-primary-400 font-semibold text-chrome-900'
                                    : (isToday(day) ? 'font-semibold text-primary-700 ring-1 ring-primary-400' : 'text-chrome-700 hover:bg-chrome-100')"
                                x-text="day"></button>
                        <span x-show="day === null" class="block py-1.5">&nbsp;</span>
                    </div>
                </template>
            </div>

            @if ($withTime)
                <div class="mt-3 flex items-center gap-2 border-t border-chrome-100 pt-3">
                    <label class="text-xs font-medium text-chrome-500" for="{{ $timeId }}">{{ __('Time') }}</label>
                    <input type="time" id="{{ $timeId }}" x-model="time" x-on:change="applyTime()"
                           class="o-input w-32 text-sm">
                </div>
            @endif

            {{-- The weekday a date falls on, said in words rather than left to
                 be counted off the grid — and a plain way out. Tapping outside
                 still closes the panel, but nothing on screen SAID so, which on
                 a phone reads as being stuck in it. --}}
            <div class="mt-3 flex items-center justify-between gap-2">
                <p class="text-xs text-chrome-500" x-text="chosenSummary"></p>
                <button type="button" x-on:click="confirmCalendar()"
                        class="o-btn-primary shrink-0 px-3 py-1 text-xs">{{ __('Done') }}</button>
            </div>
        </div>
    @endif
</div>
