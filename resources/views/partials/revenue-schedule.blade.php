{{--
    Where this period's money came from, under the figure it explains.

    The rows always add up to the figure above them: same paid-only filter,
    same dates, and everything past the top few carried in an "others" row
    rather than dropped. A breakdown that does not reconcile with its own
    headline teaches people to distrust both.

    Two things this deliberately does NOT do, both learned the hard way:

    - It never disappears. An earlier version rendered nothing at all when the
      period had no collected money, which is indistinguishable from a feature
      that was never built - the owner went looking for it and could not find
      it. An empty period now says so.
    - It shows the single biggest source while still closed, so the panel is
      worth something before anyone thinks to click it.

    Already computed in render(), so opening it costs no query - only markup.

    Expects: $schedule (from RevenueSchedule), $key, $period.
--}}
@php
    $rows = $schedule['rows'] ?? [];
    $top = $rows[0] ?? null;
@endphp

<div class="mt-auto pt-3" x-data="{ open: false }">
    <button type="button" x-on:click="open = !open" @disabled($rows === [])
        class="flex w-full items-center justify-between gap-2 rounded-lg bg-chrome-50 px-3 py-2 text-start ring-1 ring-chrome-100 transition enabled:hover:bg-chrome-100 disabled:opacity-60">
        <span class="min-w-0">
            <span class="block text-[11px] font-bold uppercase tracking-wide text-chrome-500">{{ __('Where it came from') }}</span>
            @if ($top !== null)
                {{-- The biggest single source, readable without opening. --}}
                <span class="mt-0.5 block truncate text-xs font-medium text-chrome-700">
                    {{ $top['label'] }} · {{ \App\Erp\Views\ValueFormat::money($top['amount']) }} ({{ $top['share'] }}%)
                </span>
            @else
                <span class="mt-0.5 block text-xs font-medium text-chrome-400">{{ __('Nothing collected in this period yet.') }}</span>
            @endif
        </span>
        @if ($rows !== [])
            <svg class="size-4 shrink-0 text-chrome-400 transition" x-bind:class="open && 'rotate-180'" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd"/></svg>
        @endif
    </button>

    @if ($rows !== [])
        <div x-show="open" x-cloak class="mt-3 space-y-2">
            <p class="text-[11px] font-medium text-chrome-400">
                {{ __(':dimension · :period', ['dimension' => $schedule['dimension'], 'period' => $period]) }}
            </p>

            @foreach ($rows as $row)
                <div>
                    <div class="flex items-baseline justify-between gap-2 text-xs">
                        <span class="truncate font-medium text-chrome-700">{{ $row['label'] }}</span>
                        <span class="shrink-0 font-bold text-chrome-900">{{ \App\Erp\Views\ValueFormat::money($row['amount']) }}</span>
                    </div>
                    <div class="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-chrome-100">
                        <div class="h-full rounded-full bg-chrome-400" style="width: {{ max(2, $row['share']) }}%"></div>
                    </div>
                    <div class="mt-1 flex items-center justify-between gap-2 text-[11px] text-chrome-400">
                        <span>{{ trans_choice('{1} :count job|[2,*] :count jobs', $row['jobs'], ['count' => $row['jobs']]) }} · {{ $row['share'] }}%</span>
                        @if ($row['pct'] !== null)
                            {{-- Only Rent A Car reaches here: a car carries its own
                                 monthly target, so its row can be scored. --}}
                            <span class="font-semibold {{ $row['pct'] >= 100 ? 'text-emerald-600' : 'text-amber-600' }}">
                                {{ __(':percent% of its target', ['percent' => $row['pct']]) }}
                            </span>
                        @endif
                    </div>
                </div>
            @endforeach

            @if ($schedule['othersCount'] > 0)
                <div class="flex items-baseline justify-between gap-2 border-t border-chrome-100 pt-2 text-xs">
                    <span class="font-medium text-chrome-500">{{ __(':count others', ['count' => $schedule['othersCount']]) }}</span>
                    <span class="font-bold text-chrome-600">{{ \App\Erp\Views\ValueFormat::money($schedule['others']) }}</span>
                </div>
            @endif
        </div>
    @endif
</div>
