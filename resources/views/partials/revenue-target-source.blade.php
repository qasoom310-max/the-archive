{{--
    Where this target came from - or why there is none.

    The earlier version said "Added up from 21 cars' own monthly targets" when
    one car had a target and twenty did not, then printed "target met" against
    that 150 BD. A fleet target now exists only when EVERY car carries one;
    until then the box says how many do, which is the thing to fix.

    Expects: $source ('typed' | 'fleet' | null), $fleet (month box only).
--}}
@php
    $gap = $source === null && $fleet !== null && $fleet['cars'] < $fleet['fleet'];
@endphp
@if ($source !== null || $gap)
    <div class="mt-2 flex items-start gap-1.5 text-[11px] leading-snug {{ $gap ? 'text-amber-700' : 'text-chrome-400' }}">
        <svg class="mt-px size-3.5 shrink-0" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M18 10A8 8 0 1 1 2 10a8 8 0 0 1 16 0Zm-7-4a1 1 0 1 1-2 0 1 1 0 0 1 2 0ZM9 9a1 1 0 0 0 0 2v3a1 1 0 0 0 1 1h1a1 1 0 1 0 0-2v-3a1 1 0 0 0-1-1H9Z" clip-rule="evenodd"/></svg>
        <span>
            @if ($source === 'typed')
                {{ __('Set by you.') }}
            @elseif ($source === 'fleet')
                {{ __('Added up from all :count cars\' monthly targets.', ['count' => $fleet['fleet'] ?? 0]) }}
            @elseif ($fleet['cars'] === 0)
                {{ __('None of your :count cars has a monthly target yet. Set them on each car page, or type a target here.', ['count' => $fleet['fleet']]) }}
            @else
                {{ __('Only :cars of your :fleet cars have a monthly target, so the fleet has none yet. Set the rest on each car page, or type a target here.', ['cars' => $fleet['cars'], 'fleet' => $fleet['fleet']]) }}
            @endif
        </span>
    </div>
@endif
