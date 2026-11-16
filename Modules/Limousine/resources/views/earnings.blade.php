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
    $monthNames = [];
    for ($m = 1; $m <= 12; $m++) {
        $monthNames[$m] = \Illuminate\Support\Carbon::create($year, $m, 1)->translatedFormat('M');
    }
    $driverPeriod = $shownMonth > 0
        ? \Illuminate\Support\Carbon::create($year, $shownMonth, 1)->translatedFormat('F Y')
        : (string) $year;
    $routeTop = max(1.0, (float) collect($report['routes']['rows'])->max('earned'));
    $serviceTotal = (float) collect($report['services'])->sum('earned');
    $serviceBars = ['bg-sky-500', 'bg-violet-500', 'bg-emerald-500', 'bg-amber-500'];
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
        <h2 class="text-xs font-bold uppercase tracking-wider text-chrome-500">{{ __('Drivers') }} · {{ $driverPeriod }}</h2>
        <span class="h-px flex-1 bg-chrome-200"></span>
    </div>
    <div class="mb-8 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/[0.06]" data-drivers>
        {{-- A driver is read by the month: this month by default, any other
             month or the whole year one press away. dir=ltr keeps January
             first for everyone. --}}
        <div class="mb-4 flex flex-wrap items-center gap-1" dir="ltr" data-driver-months>
            @foreach ($monthNames as $m => $name)
                <button type="button" wire:click="setDriverMonth({{ $m }})"
                    class="rounded-lg px-2.5 py-1 text-xs font-semibold transition {{ $shownMonth === $m ? 'bg-primary-400 text-chrome-900' : 'text-chrome-500 hover:bg-chrome-100' }}">
                    {{ $name }}
                </button>
            @endforeach
            <span class="mx-1 h-4 w-px bg-chrome-200"></span>
            <button type="button" wire:click="setDriverMonth(0)"
                class="rounded-lg px-2.5 py-1 text-xs font-semibold transition {{ $shownMonth === 0 ? 'bg-primary-400 text-chrome-900' : 'text-chrome-500 hover:bg-chrome-100' }}">
                {{ __('Whole year') }}
            </button>
        </div>

        @if (! $report['drivers']['available'])
            {{-- A statement about the records, not an empty table to puzzle over. --}}
            <p class="py-6 text-center text-sm text-chrome-400">
                {{ __('No trip in :period names a driver, so there is nobody to rank. Assign a driver on the trip and this fills in.', ['period' => $driverPeriod]) }}
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
                                <td class="py-2.5 font-semibold text-chrome-900">
                                    <span class="me-2 inline-flex size-5 items-center justify-center rounded-full text-[10px] font-bold {{ $loop->first ? 'bg-primary-400 text-chrome-900' : 'bg-chrome-100 text-chrome-500' }}">{{ $loop->iteration }}</span>{{ $row['name'] }}
                                    @if ($loop->first && $row['earned'] > 0)
                                        <span class="ms-2 inline-flex whitespace-nowrap rounded-full bg-primary-50 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-primary-800 ring-1 ring-primary-200" data-top-driver>
                                            {{ $shownMonth > 0 ? __('Top driver of the month') : __('Top driver of the year') }}
                                        </span>
                                    @endif
                                </td>
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
            @if (($report['drivers']['office'] ?? 0) > 0)
                <p class="mt-1 text-[11px] text-chrome-400">
                    {{ __(':count trips are on an office or system account rather than a driver, and are left out too.', ['count' => number_format($report['drivers']['office'])]) }}
                </p>
            @endif
            <p class="mt-1 text-[11px] leading-snug text-chrome-400">
                {{ __('"Advanced" is petty cash handed out in the same period. It only appears for a driver held in the driver register, so older trips that name a driver in text alone show a dash.') }}
            </p>
            {{-- The names carried over are the old system's logins. Whoever is
                 looking at a row they do not recognise needs the screen that
                 explains it to be one click away, not something to be told. --}}
            <p class="mt-1 text-[11px] leading-snug text-chrome-400">
                {{ __('A name here you do not recognise is a login from the old system.') }}
                <a href="{{ url('/app/limousine/driver-names') }}" wire:navigate class="font-medium text-primary-700 hover:underline">{{ __('Say who they were') }}</a>
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
                                        <td class="py-2.5">
                                            <div class="flex items-center gap-2">
                                                <span class="inline-flex size-5 shrink-0 items-center justify-center rounded-full bg-chrome-100 text-[10px] font-bold text-chrome-500">{{ $loop->iteration }}</span>
                                                <span class="font-medium text-chrome-800">{{ $row['label'] }}</span>
                                            </div>
                                            {{-- How this route's money compares with the best one. --}}
                                            <div class="ms-7 mt-1.5 h-1 overflow-hidden rounded-full bg-chrome-100">
                                                <div class="h-full rounded-full bg-sky-500" style="width: {{ max(2, (int) round($row['earned'] / $routeTop * 100)) }}%"></div>
                                            </div>
                                        </td>
                                        <td class="py-2.5 text-end tabular-nums text-chrome-600">{{ number_format($row['trips']) }}</td>
                                        <td class="py-2.5 text-end font-bold tabular-nums text-chrome-900">{{ $money($row['earned']) }}</td>
                                        <td class="py-2.5 text-end tabular-nums text-chrome-600">{{ $money($row['avgFare']) }}</td>
                                        <td class="py-2.5 text-end">
                                            {{-- Against the SAME route a year ago, so a
                                                 cheap route is not called a decline. --}}
                                            @if ($row['shift'] === null)
                                                <span class="text-chrome-300">—</span>
                                            @else
                                                <span class="inline-flex whitespace-nowrap rounded-full px-2 py-0.5 text-[11px] font-bold tabular-nums ring-1 {{ $row['shift'] >= 0 ? 'bg-emerald-50 text-emerald-700 ring-emerald-100' : 'bg-red-50 text-red-700 ring-red-100' }}">
                                                    {{ $row['shift'] >= 0 ? '▲' : '▼' }} {{ abs($row['shift']) }}%
                                                </span>
                                            @endif
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
                    @php $share = $serviceTotal > 0 ? (int) round($row['earned'] / $serviceTotal * 100) : 0; @endphp
                    <div class="{{ ! $loop->last ? 'mb-5 border-b border-chrome-100 pb-5' : '' }}">
                        <div class="flex items-baseline justify-between gap-2">
                            <span class="flex items-center gap-2 text-sm font-semibold text-chrome-800">
                                <span class="size-2 rounded-full {{ $serviceBars[$loop->index % count($serviceBars)] }}"></span>{{ $row['label'] }}
                            </span>
                            <span class="text-lg font-bold tabular-nums text-chrome-900">{{ $share }}%</span>
                        </div>
                        <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-chrome-100">
                            <div class="h-full rounded-full {{ $serviceBars[$loop->index % count($serviceBars)] }}" style="width: {{ $share }}%"></div>
                        </div>
                        <dl class="mt-3 grid grid-cols-3 gap-2 text-xs">
                            <div><dt class="text-chrome-400">{{ __('Collected') }}</dt><dd class="font-semibold tabular-nums text-chrome-800">{{ $money($row['earned']) }}</dd></div>
                            <div><dt class="text-chrome-400">{{ __('Trips') }}</dt><dd class="font-semibold tabular-nums text-chrome-800">{{ number_format($row['trips']) }}</dd></div>
                            <div><dt class="text-chrome-400">{{ __('Average fare') }}</dt><dd class="font-semibold tabular-nums text-chrome-800">{{ $money($row['avgFare']) }}</dd></div>
                        </dl>
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
