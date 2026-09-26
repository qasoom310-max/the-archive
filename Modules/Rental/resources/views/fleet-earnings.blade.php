{{--
    Fleet earnings. The matrix the office recognises, over a scorecard that
    answers whether each car is worth owning. See FleetPerformance for why
    ranking a fleet by total revenue puts the best and worst cars the wrong
    way round.
--}}
@php
    $money = static fn (float $v): string => \App\Erp\Views\ValueFormat::money($v);
    $months = [1 => __('Jan'), __('Feb'), __('Mar'), __('Apr'), __('May'), __('Jun'), __('Jul'), __('Aug'), __('Sep'), __('Oct'), __('Nov'), __('Dec')];

    $verdicts = [
        'carrying' => ['label' => __('Carrying its weight'), 'class' => 'bg-emerald-50 text-emerald-700 ring-emerald-100'],
        'behind' => ['label' => __('Renting cheap'), 'class' => 'bg-amber-50 text-amber-700 ring-amber-100'],
        'underused' => ['label' => __('Underused'), 'class' => 'bg-sky-50 text-sky-700 ring-sky-100'],
        'losing' => ['label' => __('Losing money'), 'class' => 'bg-red-50 text-red-700 ring-red-100'],
        'notarget' => ['label' => __('No target'), 'class' => 'bg-chrome-100 text-chrome-500 ring-chrome-200'],
    ];
@endphp

<div class="mx-auto max-w-[1600px] px-4 py-6 sm:px-6">

    <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-chrome-900">{{ __('Fleet earnings') }}</h1>
            <p class="mt-1 text-sm text-chrome-500">
                {{ __('What each car earned, how hard it worked for it, and whether it is on pace.') }}
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

    {{-- Read the whole year, or one month. In a month every figure below is that
         month's, and each car is judged against its MONTHLY target. --}}
    <div class="mb-6 flex flex-wrap items-center gap-1">
        <button type="button" wire:click="setMonth(0)"
            class="rounded-lg px-3 py-1.5 text-xs font-semibold transition {{ $month === 0 ? 'bg-chrome-900 text-white' : 'bg-chrome-100 text-chrome-600 hover:bg-chrome-200' }}">
            {{ __('Whole year') }}
        </button>
        @foreach ($months as $i => $m)
            <button type="button" wire:click="setMonth({{ $i }})"
                class="rounded-lg px-3 py-1.5 text-xs font-semibold transition {{ $month === $i ? 'bg-chrome-900 text-white' : 'bg-chrome-100 text-chrome-600 hover:bg-chrome-200' }}">
                {{ $m }}
            </button>
        @endforeach
        <span class="ms-2 text-xs font-medium text-chrome-400">{{ __('Showing :period', ['period' => $period]) }}</span>
    </div>

    {{-- The headline. Not "what did we bill" but "what did standing still cost". --}}
    <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-2xl bg-gradient-to-br from-amber-500 to-orange-600 p-5 shadow-sm">
            <div class="text-[11px] font-bold uppercase tracking-wide text-white/80">{{ __('Idle cost') }}</div>
            <div class="mt-2 text-2xl font-bold tracking-tight text-white">{{ $money($summary['idleCost']) }}</div>
            <div class="mt-1 text-xs font-medium text-white/80">
                {{-- Each car's OWN achieved rate, so this is what those days
                     would really have earned, not a wish. --}}
                {{ __(':days car-days stood still, at each car\'s own rate', ['days' => number_format($summary['idleDays'])]) }}
            </div>
        </div>

        <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/[0.06]">
            <div class="text-[11px] font-bold uppercase tracking-wide text-chrome-400">{{ __('Earned') }}</div>
            <div class="mt-2 text-2xl font-bold tracking-tight text-chrome-900">{{ $money($summary['total']) }}</div>
            <div class="mt-1 text-xs font-medium text-chrome-500">
                @if ($summary['target'] > 0)
                    {{ __('of :target target', ['target' => $money($summary['target'])]) }}
                @else
                    {{ __('No car targets set yet') }}
                @endif
            </div>
        </div>

        <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/[0.06]">
            <div class="text-[11px] font-bold uppercase tracking-wide text-chrome-400">{{ __('Utilisation') }}</div>
            <div class="mt-2 text-2xl font-bold tracking-tight text-chrome-900">
                {{ $summary['utilisation'] === null ? '—' : $summary['utilisation'].'%' }}
            </div>
            <div class="mt-1 text-xs font-medium text-chrome-500">
                {{ __(':rented of :available car-days on hire', [
                    'rented' => number_format($summary['rentedDays']),
                    'available' => number_format($summary['availableDays']),
                ]) }}
            </div>
        </div>

        <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/[0.06]">
            <div class="text-[11px] font-bold uppercase tracking-wide text-chrome-400">{{ __('After maintenance') }}</div>
            <div class="mt-2 text-2xl font-bold tracking-tight {{ $summary['net'] < 0 ? 'text-red-600' : 'text-chrome-900' }}">{{ $money($summary['net']) }}</div>
            <div class="mt-1 text-xs font-medium text-chrome-500">
                {{ __(':amount spent on service and repairs', ['amount' => $money($summary['maintenance'])]) }}
            </div>
        </div>
    </div>

    @if ($summary['best'] !== null && $summary['worst'] !== null && $summary['best']['id'] !== $summary['worst']['id'])
        {{-- Ranked by earnings per AVAILABLE day, which is the comparison the
             revenue column gets backwards. --}}
        <div class="mb-6 grid gap-4 sm:grid-cols-2">
            <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-emerald-100">
                <div class="text-[11px] font-bold uppercase tracking-wide text-emerald-600">{{ __('Best earner per day') }}</div>
                <div class="mt-1 font-semibold text-chrome-900">{{ $summary['best']['name'] }} {{ $summary['best']['plate'] }}</div>
                <div class="text-sm text-chrome-500">
                    {{ __(':amount per available day · :pct% used', ['amount' => $money($summary['best']['perAvailableDay']), 'pct' => $summary['best']['utilisation']]) }}
                </div>
            </div>
            <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-red-100">
                <div class="text-[11px] font-bold uppercase tracking-wide text-red-600">{{ __('Weakest earner per day') }}</div>
                <div class="mt-1 font-semibold text-chrome-900">{{ $summary['worst']['name'] }} {{ $summary['worst']['plate'] }}</div>
                <div class="text-sm text-chrome-500">
                    {{ __(':amount per available day · :pct% used', ['amount' => $money($summary['worst']['perAvailableDay']), 'pct' => $summary['worst']['utilisation']]) }}
                </div>
            </div>
        </div>
    @endif

    {{-- Downloads, in the same shape as every other list in the app. --}}
    <div class="mb-3 flex flex-wrap items-center gap-2">
        <h2 class="text-xs font-bold uppercase tracking-wider text-chrome-500">{{ __('Car by car') }}</h2>
        <span class="h-px flex-1 bg-chrome-200"></span>
        <button type="button" onclick="copyTableById('fleet-table')" class="rounded-lg bg-chrome-100 px-3 py-1.5 text-xs font-semibold text-chrome-600 transition hover:bg-chrome-200">{{ __('Copy') }}</button>
        @foreach ([['csv', __('CSV')], ['excel', __('Excel')], ['pdf', __('PDF')], ['print', __('Print')]] as [$fmt, $label])
            {{-- url(), not route(): a module's routes only exist while it is
                 installed, so a named-route lookup in a view is fragile here. --}}
            <a href="{{ url('/app/rental/fleet/export?year='.$year.'&month='.$month.'&format='.$fmt) }}"
                class="rounded-lg bg-chrome-100 px-3 py-1.5 text-xs font-semibold text-chrome-600 transition hover:bg-chrome-200">{{ $label }}</a>
        @endforeach
    </div>

    <div class="overflow-x-auto rounded-2xl bg-white shadow-sm ring-1 ring-chrome-900/[0.06]">
        <table id="fleet-table" class="w-full min-w-[80rem] text-sm">
            <thead>
                <tr class="border-b border-chrome-200 text-[11px] font-bold uppercase tracking-wider text-chrome-400">
                    <th class="px-3 py-2.5 text-start">{{ __('Reg#') }}</th>
                    <th class="px-3 py-2.5 text-start">{{ __('Vehicle') }}</th>
                    @foreach ($months as $i => $m)
                        <th class="px-2 py-2.5 text-end {{ $month === $i ? 'bg-primary-100 text-chrome-800' : '' }}">{{ $m }}</th>
                    @endforeach
                    <th class="px-3 py-2.5 text-end">{{ $month > 0 ? $months[$month] : __('Total') }}</th>
                    <th class="px-3 py-2.5 text-end">{{ __('Used') }}</th>
                    <th class="px-3 py-2.5 text-end">{{ __('Per day') }}</th>
                    <th class="px-3 py-2.5 text-end">{{ __('Pace') }}</th>
                    <th class="px-3 py-2.5 text-start ps-4">{{ __('Verdict') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-chrome-100">
                @foreach ($rows as $row)
                    @php $v = $verdicts[$row['verdict']] ?? $verdicts['notarget']; @endphp
                    <tr class="{{ $row['verdict'] === 'losing' ? 'bg-red-50/40' : '' }} {{ $row['id'] !== null ? 'cursor-pointer hover:bg-chrome-50' : '' }}"
                        @if ($row['id'] !== null) wire:click="toggleCar({{ $row['id'] }})" @endif>
                        <td class="px-3 py-2.5 text-chrome-500">{{ $row['plate'] ?: '—' }}</td>
                        <td class="px-3 py-2.5 font-semibold text-chrome-900">
                            {{ $row['name'] }}
                            @if ($row['retired'])
                                <span class="ms-1 rounded bg-chrome-100 px-1.5 py-0.5 text-[10px] font-bold uppercase text-chrome-500">{{ __('Retired') }}</span>
                            @endif
                        </td>
                        @foreach ($months as $i => $m)
                            @php $val = $row['months'][$i] ?? 0.0; @endphp
                            <td class="px-2 py-2.5 text-end {{ $val > 0 ? 'text-emerald-700' : 'text-chrome-300' }} {{ $month === $i ? 'bg-primary-50 font-bold' : '' }}">
                                {{ $val > 0 ? number_format($val, 0) : '0' }}
                            </td>
                        @endforeach
                        <td class="px-3 py-2.5 text-end font-bold text-chrome-900">{{ $money($row['total']) }}</td>
                        <td class="px-3 py-2.5 text-end text-chrome-600">{{ $row['utilisation'] === null ? '—' : $row['utilisation'].'%' }}</td>
                        <td class="px-3 py-2.5 text-end text-chrome-600">{{ $row['availableDays'] > 0 ? $money($row['perAvailableDay']) : '—' }}</td>
                        <td class="px-3 py-2.5 text-end font-semibold {{ $row['pace'] === null ? 'text-chrome-300' : ($row['pace'] >= 100 ? 'text-emerald-600' : 'text-amber-600') }}">
                            {{ $row['pace'] === null ? '—' : $row['pace'].'%' }}
                        </td>
                        <td class="px-3 py-2.5 ps-4">
                            <span class="inline-flex whitespace-nowrap rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide ring-1 {{ $v['class'] }}">{{ $v['label'] }}</span>
                        </td>
                    </tr>

                    @if ($openCar === $row['id'] && $row['id'] !== null)
                        <tr class="bg-chrome-50">
                            <td colspan="{{ 18 }}" class="px-4 py-4">
                                @include('rental::partials.fleet-scorecard', ['row' => $row, 'report' => $report])
                            </td>
                        </tr>
                    @endif
                @endforeach

                @if ($rows === [])
                    <tr><td colspan="18" class="px-4 py-10 text-center text-sm text-chrome-400">{{ __('No cars earned anything in :year.', ['year' => $year]) }}</td></tr>
                @endif
            </tbody>
        </table>
    </div>

    <p class="mt-3 text-[11px] leading-snug text-chrome-400">
        {{ __('Revenue is net of what outside vendors are paid. "Per day" is earnings divided by days the car was available, not days it was hired — a car earns nothing on the days it stands still. Click a car for its full scorecard.') }}
    </p>
</div>
