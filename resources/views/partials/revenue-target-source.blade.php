{{--
    Where this target came from.

    A number on a dashboard that nobody remembers setting stops being believed,
    and an owner cannot argue with a figure whose working is hidden. Rent A Car
    can answer the question properly, because every car already carries its own
    monthly target: the fleet total is those added up, not a number retyped here
    that would then drift from the cars it came from.

    Expects: $source ('typed' | 'fleet' | 'fleet_estimate' | null), $fleet.
--}}
@if ($source !== null)
    <div class="mt-2 flex items-start gap-1.5 text-[11px] leading-snug text-chrome-400">
        <svg class="mt-px size-3.5 shrink-0" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M18 10A8 8 0 1 1 2 10a8 8 0 0 1 16 0Zm-7-4a1 1 0 1 1-2 0 1 1 0 0 1 2 0ZM9 9a1 1 0 0 0 0 2v3a1 1 0 0 0 1 1h1a1 1 0 1 0 0-2v-3a1 1 0 0 0-1-1H9Z" clip-rule="evenodd"/></svg>
        <span>
            @if ($source === 'typed')
                {{ __('Set by you.') }}
            @elseif ($source === 'fleet' && $fleet !== null)
                {{ __('Added up from :count cars\' own monthly targets.', ['count' => $fleet['cars']]) }}
            @elseif ($fleet !== null)
                {{-- Twelve equal months is not how this trade runs, so this is
                     offered as a starting point, never as a real yearly plan. --}}
                {{ __('Estimated as 12 × the fleet\'s monthly target. Set your own for a real year.', ['count' => $fleet['cars']]) }}
            @endif
        </span>
    </div>
@endif
