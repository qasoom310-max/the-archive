{{--
    Limousine earnings: drivers, routes and the hours the work lands in.

    There is no per-car page here on purpose. No trip ever recorded a car, so
    one would be a single row reading "not recorded" — the mistake the first
    revenue breakdown made. Each section below says so plainly when its own
    records are missing, rather than rendering an empty table.
--}}
@php
    $money = static fn (float $v): string => \App\Erp\Views\ValueFormat::money($v);
    $summary = $report['summary'];
    $days = [__('Sun'), __('Mon'), __('Tue'), __('Wed'), __('Thu'), __('Fri'), __('Sat')];
    $bands = [
        'early' => __('06–10'),
        'day' => __('10–16'),
        'evening' => __('16–22'),
        'night' => __('22–06'),
    ];
    $verdicts = [
        'premium' => ['label' => __('Premium work'), 'class' => 'bg-emerald-50 text-emerald-700 ring-emerald-100'],
        'cheap' => ['label' => __('Low fares'), 'class' => 'bg-amber-50 text-amber-700 ring-amber-100'],
        'steady' => ['label' => __('Around average'), 'class' => 'bg-chrome-100 text-chrome-500 ring-chrome-200'],
    ];
    $peak = 0;
    foreach ($report['demand']['grid'] as $bandRow) {
        foreach ($bandRow as $cell) { $peak = max($peak, $cell['trips']); }
    }
@endphp

<div class="mx-auto max-w-[1400px] px-4 py-6 sm:px-6">

    <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-chrome-900">{{ __('Limousine earnings') }}</h1>
            <p class="mt-1 text-sm text-chrome-500">
                {{ __('Who drives the money, which journeys earn it, and when the work lands.') }}
            </p>
        </div>
        <div class="flex items-center gap-1">
            @foreach ($years as $y)
                <button type="button" wire:click="setYear({{ $y }})"
                    class="rounded-lg px-3 py-1.5 text-sm font-semibold transition {{ $y === $year ? 'bg-primary-400 text-chrome-900' : 'text-chrome-500 hover:bg-chrome-100' }}">
                    {{ $y }}
                </button>
            @endforeach
        </div>
    </div>

    {{-- The headline is the average fare, not the total: a desk can run more
         trips than ever while quietly discounting itself into trouble, and the
         trip count alone would call that a good year. --}}
    <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-2xl bg-gradient-to-br from-indigo-600 to-violet-700 p-5 shadow-sm">
            <div class="text-[11px] font-bold uppercase tracking-wide text-white/80">{{ __('Average fare') }}</div>
            <div class="mt-2 text-2xl font-bold tracking-tight text-white">{{ $money($summary['avgFare']) }}</div>
            <div class="mt-1 text-xs font-medium text-white/80">
                @if ($summary['fareShift'] === null)
                    {{ __('No comparison for last year') }}
                @elseif ($summary['fareShift'] >= 0)
                    {{ __('Up :pct% on last year (:was)', ['pct' => $summary['fareShift'], 'was' => $money($summary['lastAvgFare'])]) }}
                @else
                    {{ __('Down :pct% on last year (:was)', ['pct' => abs($summary['fareShift']), 'was' => $money($summary['lastAvgFare'])]) }}
                @endif
            </div>
        </div>

        <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/[0.06]">
            <div class="text-[11px] font-bold uppercase tracking-wide text-chrome-400">{{ __('Collected') }}</div>
            <div class="mt-2 text-2xl font-bold tracking-tight text-chrome-900">{{ $money($summary['earned']) }}</div>
            <div class="mt-1 text-xs font-medium text-chrome-500">{{ __('across :count paid trips', ['count' => number_format($summary['paidTrips'])]) }}</div>
        </div>

        <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/[0.06]">
            <div class="text-[11px] font-bold uppercase tracking-wide text-chrome-400">{{ __('Not yet collected') }}</div>
            <div class="mt-2 text-2xl font-bold tracking-tight {{ $summary['unpaid'] > 0 ? 'text-red-600' : 'text-chrome-900' }}">{{ $money($summary['unpaid']) }}</div>
            <div class="mt-1 text-xs font-medium text-chrome-500">{{ __('on trips already run') }}</div>
        </div>

        <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/[0.06]">
            <div class="text-[11px] font-bold uppercase tracking-wide text-chrome-400">{{ __('Trips run') }}</div>
            <div class="mt-2 text-2xl font-bold tracking-tight text-chrome-900">{{ number_format($summary['trips']) }}</div>
            <div class="mt-1 text-xs font-medium text-chrome-500">{{ __('cancelled trips excluded') }}</div>
        </div>
    </div>

    {{-- Drivers --}}
    <div class="mb-3 flex items-center gap-2">
        <h2 class="text-xs font-bold uppercase tracking-wider text-chrome-500">{{ __('Drivers') }}</h2>
        <span class="h-px flex-1 bg-chrome-200"></span>
    </div>
    <div class="mb-8 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/[0.06]">
        @if (! $report['drivers']['available'])
            {{-- A statement about the records, not an empty table to puzzle over. --}}
            <p class="py-6 text-center text-sm text-chrome-400">
                {{ __('No trip in :year names a driver, so there is nobody to rank. Assign a driver on the trip and this fills in.', ['year' => $year]) }}
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[42rem] text-sm">
                    <thead>
                        <tr class="border-b border-chrome-200 text-[11px] font-bold uppercase tracking-wider text-chrome-400">
                            <th class="py-2 text-start">{{ __('Driver') }}</th>
                            <th class="py-2 text-end">{{ __('Collected') }}</th>
                            <th class="py-2 text-end">{{ __('Trips') }}</th>
                            <th class="py-2 text-end">{{ __('Average fare') }}</th>
                            <th class="py-2 text-end">{{ __('Not yet collected') }}</th>
                            <th class="py-2 text-end">{{ __('Advanced') }}</th>
                            <th class="py-2 text-start ps-4">{{ __('Verdict') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-chrome-100">
                        @foreach ($report['drivers']['rows'] as $row)
                            @php $v = $verdicts[$row['verdict']] ?? $verdicts['steady']; @endphp
                            <tr>
                                <td class="py-2.5 font-semibold text-chrome-900">{{ $row['name'] }}</td>
                                <td class="py-2.5 text-end font-bold text-chrome-900">{{ $money($row['earned']) }}</td>
                                <td class="py-2.5 text-end text-chrome-600">{{ number_format($row['trips']) }}</td>
                                <td class="py-2.5 text-end text-chrome-600">{{ $money($row['avgFare']) }}</td>
                                <td class="py-2.5 text-end {{ $row['unpaid'] > 0 ? 'text-red-600' : 'text-chrome-400' }}">{{ $money($row['unpaid']) }}</td>
                                <td class="py-2.5 text-end text-chrome-600">{{ $row['advanced'] > 0 ? $money($row['advanced']) : '—' }}</td>
                                <td class="py-2.5 ps-4">
                                    <span class="inline-flex whitespace-nowrap rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide ring-1 {{ $v['class'] }}">{{ $v['label'] }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($report['drivers']['unnamed'] > 0)
                <p class="mt-3 text-[11px] text-chrome-400">
                    {{ __(':count trips name no driver and are left out of this table.', ['count' => number_format($report['drivers']['unnamed'])]) }}
                </p>
            @endif
            <p class="mt-1 text-[11px] leading-snug text-chrome-400">
                {{ __('"Advanced" is petty cash handed out this year. It only appears for a driver held in the driver register, so older trips that name a driver in text alone show a dash.') }}
            </p>
        @endif
    </div>

    {{-- Routes + services --}}
    <div class="mb-8 grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <div class="mb-3 flex items-center gap-2">
                <h2 class="text-xs font-bold uppercase tracking-wider text-chrome-500">{{ __('Routes') }}</h2>
                <span class="h-px flex-1 bg-chrome-200"></span>
            </div>
            <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/[0.06]">
                @if (! $report['routes']['available'])
                    <p class="py-6 text-center text-sm text-chrome-400">
                        {{ __('No trip in :year records where it went, so routes cannot be compared.', ['year' => $year]) }}
                    </p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[34rem] text-sm">
                            <thead>
                                <tr class="border-b border-chrome-200 text-[11px] font-bold uppercase tracking-wider text-chrome-400">
                                    <th class="py-2 text-start">{{ __('Route') }}</th>
                                    <th class="py-2 text-end">{{ __('Trips') }}</th>
                                    <th class="py-2 text-end">{{ __('Collected') }}</th>
                                    <th class="py-2 text-end">{{ __('Average fare') }}</th>
                                    <th class="py-2 text-end">{{ __('vs last year') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-chrome-100">
                                @foreach ($report['routes']['rows'] as $row)
                                    <tr>
                                        <td class="py-2.5 font-medium text-chrome-800">{{ $row['label'] }}</td>
                                        <td class="py-2.5 text-end text-chrome-600">{{ number_format($row['trips']) }}</td>
                                        <td class="py-2.5 text-end font-bold text-chrome-900">{{ $money($row['earned']) }}</td>
                                        <td class="py-2.5 text-end text-chrome-600">{{ $money($row['avgFare']) }}</td>
                                        <td class="py-2.5 text-end font-semibold {{ $row['shift'] === null ? 'text-chrome-300' : ($row['shift'] >= 0 ? 'text-emerald-600' : 'text-red-600') }}">
                                            {{-- Against the SAME route a year ago, so a
                                                 cheap route is not called a decline. --}}
                                            {{ $row['shift'] === null ? '—' : ($row['shift'] >= 0 ? '+' : '').$row['shift'].'%' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if ($report['routes']['unnamed'] > 0)
                        <p class="mt-3 text-[11px] text-chrome-400">
                            {{ __(':count trips record no pickup or drop-off and are left out.', ['count' => number_format($report['routes']['unnamed'])]) }}
                        </p>
                    @endif
                @endif
            </div>
        </div>

        <div>
            <div class="mb-3 flex items-center gap-2">
                <h2 class="text-xs font-bold uppercase tracking-wider text-chrome-500">{{ __('Kind of work') }}</h2>
                <span class="h-px flex-1 bg-chrome-200"></span>
            </div>
            <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/[0.06]">
                @forelse ($report['services'] as $row)
                    <div class="{{ ! $loop->last ? 'mb-4 border-b border-chrome-100 pb-4' : '' }}">
                        <div class="flex items-baseline justify-between gap-2">
                            <span class="text-sm font-medium text-chrome-700">{{ $row['label'] }}</span>
                            <span class="font-bold text-chrome-900">{{ $money($row['earned']) }}</span>
                        </div>
                        <div class="mt-1 text-xs text-chrome-400">
                            {{ __(':count trips · :avg average', ['count' => number_format($row['trips']), 'avg' => $money($row['avgFare'])]) }}
                        </div>
                    </div>
                @empty
                    <p class="py-6 text-center text-sm text-chrome-400">{{ __('No trips in :year.', ['year' => $year]) }}</p>
                @endforelse
            </div>
        </div>
    </div>

    {{-- Demand --}}
    <div class="mb-3 flex items-center gap-2">
        <h2 class="text-xs font-bold uppercase tracking-wider text-chrome-500">{{ __('When the work lands') }}</h2>
        <span class="h-px flex-1 bg-chrome-200"></span>
    </div>
    <div class="mb-4 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/[0.06]">
        {{-- dir=ltr: this is a grid of times, and mirroring it would put the
             week backwards for an Arabic reader. --}}
        <div class="overflow-x-auto" dir="ltr">
            <table class="w-full min-w-[34rem] text-sm">
                <thead>
                    <tr class="text-[11px] font-bold uppercase tracking-wider text-chrome-400">
                        <th class="py-2 text-left"></th>
                        @foreach ($days as $d)
                            <th class="px-2 py-2 text-center">{{ $d }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($bands as $key => $label)
                        <tr>
                            <td class="py-1 pr-3 text-xs font-semibold text-chrome-500">{{ $label }}</td>
                            @for ($dow = 0; $dow < 7; $dow++)
                                @php
                                    $cell = $report['demand']['grid'][$key][$dow];
                                    $share = $peak > 0 ? $cell['trips'] / $peak : 0.0;
                                    $tone = match (true) {
                                        $cell['trips'] === 0 => 'bg-chrome-50 text-chrome-300',
                                        $share >= 0.75 => 'bg-indigo-600 text-white',
                                        $share >= 0.5 => 'bg-indigo-400 text-white',
                                        $share >= 0.25 => 'bg-indigo-200 text-indigo-900',
                                        default => 'bg-indigo-50 text-indigo-700',
                                    };
                                    $avg = $cell['trips'] > 0 ? $cell['earned'] / $cell['trips'] : 0.0;
                                @endphp
                                <td class="p-1">
                                    <div class="rounded-lg px-2 py-2 text-center {{ $tone }}"
                                        title="{{ __(':count trips · :avg average', ['count' => $cell['trips'], 'avg' => $money($avg)]) }}">
                                        <div class="text-sm font-bold">{{ $cell['trips'] ?: '·' }}</div>
                                    </div>
                                </td>
                            @endfor
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        @php
            $cellLabel = static function (?string $key) use ($bands, $days): string {
                if ($key === null) { return __('Not enough trips yet'); }
                [$band, $dow] = explode('|', $key);
                return $days[(int) $dow].' '.$bands[$band];
            };
        @endphp
        <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-indigo-100">
            <div class="text-[11px] font-bold uppercase tracking-wide text-indigo-600">{{ __('Busiest slot') }}</div>
            <div class="mt-1 font-semibold text-chrome-900">{{ $cellLabel($report['demand']['busiest']) }}</div>
            <div class="text-sm text-chrome-500">{{ __('Staff for this one.') }}</div>
        </div>
        <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-emerald-100">
            <div class="text-[11px] font-bold uppercase tracking-wide text-emerald-600">{{ __('Best paying slot') }}</div>
            <div class="mt-1 font-semibold text-chrome-900">{{ $cellLabel($report['demand']['bestPaying']) }}</div>
            {{-- Volume alone would send everyone to the busiest hour. A quiet
                 band at double the fare is often the one worth chasing. --}}
            <div class="text-sm text-chrome-500">{{ __('Highest average fare. Worth advertising into.') }}</div>
        </div>
    </div>

    <p class="mt-4 text-[11px] leading-snug text-chrome-400">
        {{ __('Every figure counts trips, not bookings — a driver drives a trip and a route is a trip. Money is what has been collected; trips already run but unpaid are shown separately. There is no per-car table because no trip records a car.') }}
    </p>
</div>
