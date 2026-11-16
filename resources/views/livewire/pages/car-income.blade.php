{{--
    Car income: one row per car, one column per month, Rent A Car and Limousine
    together, against the car's yearly target.

    Month cells print plain figures with no currency sign (the heading names
    the currency once): twelve columns of "BD" would bury the numbers. They use
    the active currency's own decimals, so they still follow the money setting.
--}}
@php
    $currency = \App\Erp\Money\Currencies::active();
    $money = static fn (float $v): string => \App\Erp\Views\ValueFormat::money($v);
    $plain = static fn (float $v): string => $v > 0.0 ? number_format($v, $currency->decimals) : '—';
    $monthNames = [];
    for ($m = 1; $m <= 12; $m++) {
        $monthNames[$m] = \Illuminate\Support\Carbon::create($year, $m, 1)->translatedFormat('M');
    }
    $totals = $report['totals'];
    $both = $mode === 'all';
    $outsideFleet = $fleetShown === \App\Erp\Reports\CarIncome::OUTSIDE;
    $fleets = [
        \App\Erp\Reports\CarIncome::OWN => [__('Our cars'), $report['counts']['own']],
        \App\Erp\Reports\CarIncome::OUTSIDE => [__('Outside cars'), $report['counts']['outside']],
    ];
    // What a cell prints in the chosen view.
    $pick = static fn (array $cell): float => $mode === 'rent' ? $cell['rent'] : ($mode === 'limo' ? $cell['limo'] : $cell['total']);
    $modes = ['all' => __('Rent + Limousine'), 'rent' => __('Rent A Car'), 'limo' => __('Limousine')];
    $bar = static function (?int $percent): string {
        return match (true) {
            $percent === null => 'bg-chrome-300',
            $percent >= 100 => 'bg-emerald-500',
            $percent >= 60 => 'bg-sky-500',
            $percent >= 30 => 'bg-amber-500',
            default => 'bg-red-500',
        };
    };
    $th = 'px-2 py-2 text-end text-[11px] font-bold uppercase tracking-wider text-chrome-400';
@endphp

<div class="mx-auto max-w-[1500px] px-4 py-6 sm:px-6">

    <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-chrome-900">{{ __('Car income') }}</h1>
            <p class="mt-1 text-sm text-chrome-500">
                {{ __('What each car brought in every month, from Rent A Car and Limousine together, against its yearly target.') }}
            </p>
        </div>
        <div class="flex items-center gap-1" data-car-income-years>
            @foreach ($years as $y)
                <button type="button" wire:click="setYear({{ $y }})"
                    class="rounded-lg px-3 py-1.5 text-sm font-semibold transition {{ $y === $year ? 'bg-primary-400 text-chrome-900' : 'text-chrome-500 hover:bg-chrome-100' }}">
                    {{ $y }}
                </button>
            @endforeach
        </div>
    </div>

    {{-- Two schedules, never mixed: the cars we own, and the cars rented in
         from other companies. --}}
    <div class="mb-5 inline-flex items-center gap-1 rounded-xl bg-white p-1 shadow-sm ring-1 ring-chrome-900/[0.06]" data-car-income-fleets>
        @foreach ($fleets as $key => [$label, $count])
            <button type="button" wire:click="setFleet('{{ $key }}')"
                class="flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold transition {{ $fleetShown === $key ? 'bg-primary-400 text-chrome-900' : 'text-chrome-600 hover:bg-chrome-100' }}">
                {{ $label }}
                <span class="rounded-full px-1.5 py-0.5 text-[11px] font-bold tabular-nums {{ $fleetShown === $key ? 'bg-black/10 text-chrome-900' : 'bg-chrome-100 text-chrome-500' }}">{{ $count }}</span>
            </button>
        @endforeach
    </div>

    @if (! $report['available'])
        <div class="rounded-2xl bg-white p-10 text-center text-sm text-chrome-500 shadow-sm ring-1 ring-chrome-900/[0.06]">
            {{ $outsideFleet ? __('No outside car earned anything in :year.', ['year' => $year]) : __('No car earned anything in :year.', ['year' => $year]) }}
        </div>
    @else
        {{-- The year in four figures. --}}
        <div class="mb-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4" data-car-income-summary>
            <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/[0.06]">
                <div class="text-[11px] font-bold uppercase tracking-wide text-chrome-400">{{ __('Total income :year', ['year' => $year]) }}</div>
                <div class="mt-2 text-2xl font-bold tracking-tight text-chrome-900">{{ $money($totals['total']) }}</div>
                <div class="mt-1 text-xs font-medium text-chrome-500">{{ $outsideFleet ? __('both desks, outside cars only') : __('both desks, our own cars') }}</div>
            </div>
            <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/[0.06]">
                <div class="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wide text-chrome-400">
                    <span class="size-2 rounded-full bg-sky-500"></span>{{ __('Rent A Car') }}
                </div>
                <div class="mt-2 text-2xl font-bold tracking-tight text-chrome-900">{{ $money($totals['rent']) }}</div>
                <div class="mt-1 text-xs font-medium text-chrome-500">{{ $totals['total'] > 0 ? round($totals['rent'] / $totals['total'] * 100) : 0 }}% {{ __('of the total') }}</div>
            </div>
            <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/[0.06]">
                <div class="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wide text-chrome-400">
                    <span class="size-2 rounded-full bg-violet-500"></span>{{ __('Limousine') }}
                </div>
                <div class="mt-2 text-2xl font-bold tracking-tight text-chrome-900">{{ $money($totals['limo']) }}</div>
                <div class="mt-1 text-xs font-medium text-chrome-500">{{ $totals['total'] > 0 ? round($totals['limo'] / $totals['total'] * 100) : 0 }}% {{ __('of the total') }}</div>
            </div>
            <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/[0.06]">
                <div class="text-[11px] font-bold uppercase tracking-wide text-chrome-400">{{ __('Against the cars\' targets') }}</div>
                @if ($totals['percent'] === null)
                    <div class="mt-2 text-2xl font-bold tracking-tight text-chrome-400">—</div>
                    <div class="mt-1 text-xs font-medium text-chrome-500">{{ __('No car has a target yet. Set one on the car\'s page.') }}</div>
                @else
                    <div class="mt-2 text-2xl font-bold tracking-tight text-chrome-900">{{ $totals['percent'] }}%</div>
                    <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-chrome-100">
                        <div class="h-full rounded-full {{ $bar($totals['percent']) }}" style="width: {{ min(100, $totals['percent']) }}%"></div>
                    </div>
                    <div class="mt-1.5 text-xs font-medium text-chrome-500">
                        {{ __(':earned of :target · :left to go', ['earned' => $money($totals['onCars']), 'target' => $money($totals['target']), 'left' => $money((float) $totals['remaining'])]) }}
                    </div>
                @endif
            </div>
        </div>

        <div class="rounded-2xl bg-white shadow-sm ring-1 ring-chrome-900/[0.06]">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-chrome-100 px-5 py-3">
                <div class="text-sm font-semibold text-chrome-800">
                    {{ $outsideFleet ? __('Monthly income per category') : __('Monthly income per car') }}
                    <span class="font-normal text-chrome-400">· {{ __('figures in :currency', ['currency' => $currency->code]) }}</span>
                </div>
                <div class="flex items-center gap-1 rounded-lg bg-chrome-100 p-0.5" data-car-income-show>
                    @foreach ($modes as $key => $label)
                        <button type="button" wire:click="setShow('{{ $key }}')"
                            class="rounded-md px-3 py-1 text-xs font-semibold transition {{ $mode === $key ? 'bg-white text-chrome-900 shadow-sm' : 'text-chrome-500 hover:text-chrome-800' }}">
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
            </div>

            {{-- dir=ltr: a calendar of months reads January to December for
                 everyone; mirroring it would run the year backwards. --}}
            <div class="overflow-x-auto" dir="ltr">
                <table class="w-full min-w-[78rem] text-sm" data-car-income-table>
                    <thead>
                        <tr class="border-b border-chrome-200">
                            <th class="sticky left-0 z-10 bg-white px-5 py-2 text-left text-[11px] font-bold uppercase tracking-wider text-chrome-400">{{ $outsideFleet ? __('Category') : __('Car') }}</th>
                            @foreach ($monthNames as $m => $name)
                                <th class="{{ $th }} {{ $m === $currentMonth ? 'bg-primary-50 text-chrome-700' : '' }}">{{ $name }}</th>
                            @endforeach
                            <th class="{{ $th }} border-l border-chrome-200">{{ __('Year') }}</th>
                            <th class="{{ $th }}">{{ __('Target') }}</th>
                            <th class="px-5 py-2 text-left text-[11px] font-bold uppercase tracking-wider text-chrome-400">{{ __('Reached') }}</th>
                        </tr>
                    </thead>
                    @if ($outsideFleet)
                        {{-- Outside cars are too many to read one by one: the
                             schedule lists their categories, and pressing one
                             opens the cars inside it. --}}
                        @foreach ($report['groups'] as $group)
                            <tbody x-data="{ open: false }" wire:key="group-{{ $group['category'] === '' ? 'none' : $group['category'] }}"
                                   data-category="{{ $group['category'] === '' ? 'none' : $group['category'] }}" class="border-b border-chrome-200">
                                <tr class="cursor-pointer align-top hover:bg-chrome-50" @click="open = ! open" :aria-expanded="open.toString()">
                                    <td class="sticky left-0 z-10 bg-white px-5 py-2.5">
                                        <div class="flex items-center gap-2">
                                            <svg class="size-4 shrink-0 text-chrome-400 transition-transform" :class="open ? 'rotate-90' : ''" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.168 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd"/></svg>
                                            <span class="font-bold {{ $group['category'] === '' ? 'text-amber-700' : 'text-chrome-900' }}">
                                                {{ $group['category'] === '' ? __('No category') : ($categories[$group['category']] ?? $group['category']) }}
                                            </span>
                                        </div>
                                        <div class="ps-6 text-xs text-chrome-400">
                                            {{ __('Cars: :count', ['count' => $group['count']]) }}
                                        </div>
                                    </td>
                                    @include('livewire.pages.partials.car-income-cells', ['row' => $group, 'strong' => true] + ['mode' => $mode, 'both' => $both, 'plain' => $plain, 'pick' => $pick, 'bar' => $bar, 'currentMonth' => $currentMonth])
                                </tr>
                                @foreach ($group['cars'] as $car)
                                    <tr x-show="open" x-cloak wire:key="car-{{ $car['id'] }}" data-car="{{ $car['plate'] }}" class="bg-chrome-50/50 align-top">
                                        <td class="sticky left-0 z-10 bg-chrome-50 py-2 pe-5 ps-11">
                                            <a href="{{ url('/app/rental/vehicle/' . $car['id']) }}" wire:navigate class="font-medium text-chrome-800 hover:underline">{{ $car['name'] }}</a>
                                            <div class="text-xs text-chrome-400">
                                                {{ $car['plate'] }}@if ($car['retired']) · {{ __('retired') }}@endif
                                            </div>
                                        </td>
                                        @include('livewire.pages.partials.car-income-cells', ['row' => $car, 'strong' => false] + ['mode' => $mode, 'both' => $both, 'plain' => $plain, 'pick' => $pick, 'bar' => $bar, 'currentMonth' => $currentMonth])
                                    </tr>
                                @endforeach
                            </tbody>
                        @endforeach
                    @else
                    <tbody class="divide-y divide-chrome-100">
                        @foreach ($report['cars'] as $car)
                            <tr wire:key="car-{{ $car['id'] }}" data-car="{{ $car['plate'] }}" class="align-top hover:bg-chrome-50">
                                <td class="sticky left-0 z-10 bg-white px-5 py-2.5">
                                    <a href="{{ url('/app/rental/vehicle/' . $car['id']) }}" wire:navigate class="font-semibold text-chrome-900 hover:underline">{{ $car['name'] }}</a>
                                    <div class="text-xs text-chrome-400">
                                        {{ $car['plate'] }}@if ($car['retired']) · {{ __('retired') }}@endif
                                    </div>
                                </td>
                                @include('livewire.pages.partials.car-income-cells', ['row' => $car, 'strong' => false] + ['mode' => $mode, 'both' => $both, 'plain' => $plain, 'pick' => $pick, 'bar' => $bar, 'currentMonth' => $currentMonth])
                            </tr>
                        @endforeach

                        @foreach ($report['loose'] as $row)
                            <tr data-loose="{{ $row['kind'] }}" class="bg-chrome-50/60 align-top">
                                <td class="sticky left-0 z-10 bg-chrome-50 px-5 py-2.5">
                                    <div class="font-medium text-chrome-700">
                                        {{ $row['kind'] === 'limo' ? __('Limousine trips with no car') : __('Hires with no car') }}
                                    </div>
                                    <div class="text-xs text-chrome-400">{{ __('Not counted for any car') }}</div>
                                </td>
                                @foreach ($row['months'] as $m => $cell)
                                    <td class="px-2 py-2.5 text-end tabular-nums {{ $pick($cell) > 0 ? 'text-chrome-600' : 'text-chrome-300' }}">{{ $plain($pick($cell)) }}</td>
                                @endforeach
                                <td class="border-l border-chrome-200 px-2 py-2.5 text-end font-semibold tabular-nums text-chrome-700">{{ $plain($mode === 'rent' ? $row['rent'] : ($mode === 'limo' ? $row['limo'] : $row['total'])) }}</td>
                                <td></td>
                                <td></td>
                            </tr>
                        @endforeach
                    </tbody>
                    @endif
                    <tfoot>
                        <tr class="border-t-2 border-chrome-300 bg-chrome-50 align-top font-bold" data-car-income-total>
                            <td class="sticky left-0 z-10 bg-chrome-50 px-5 py-3 text-chrome-900">{{ __('Total') }}</td>
                            @foreach ($totals['months'] as $m => $cell)
                                <td class="px-2 py-3 text-end tabular-nums text-chrome-900">
                                    {{ $plain($pick($cell)) }}
                                    @if ($both && $cell['total'] > 0)
                                        <div class="text-[10px] font-medium leading-tight text-sky-700">{{ $plain($cell['rent']) }}</div>
                                        <div class="text-[10px] font-medium leading-tight text-violet-700">{{ $plain($cell['limo']) }}</div>
                                    @endif
                                </td>
                            @endforeach
                            <td class="border-l border-chrome-200 px-2 py-3 text-end tabular-nums text-chrome-900">{{ $plain($mode === 'rent' ? $totals['rent'] : ($mode === 'limo' ? $totals['limo'] : $totals['total'])) }}</td>
                            <td class="px-2 py-3 text-end tabular-nums text-chrome-700">{{ $totals['target'] > 0 ? $plain($totals['target']) : '—' }}</td>
                            <td class="px-5 py-3 text-xs text-chrome-700">{{ $totals['percent'] === null ? '' : $totals['percent'] . '%' }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div class="space-y-1 border-t border-chrome-100 px-5 py-3 text-[11px] leading-snug text-chrome-500">
                @if ($both)
                    <p class="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <span class="inline-flex items-center gap-1 font-semibold text-sky-700"><span class="size-2 rounded-full bg-sky-500"></span>{{ __('Rent A Car') }}</span>
                        <span class="inline-flex items-center gap-1 font-semibold text-violet-700"><span class="size-2 rounded-full bg-violet-500"></span>{{ __('Limousine') }}</span>
                        <span>{{ __('The dark figure above them is the two added together.') }}</span>
                    </p>
                @endif
                <p>{{ __('A hire counts in the month it starts and a limousine trip in the month it runs. This is work done, whether or not it has been paid yet.') }}</p>
                @if ($outsideFleet)
                    <p>{{ __('For an outside car the figure is what we kept: the hire less what the outside owner takes.') }}</p>
                    <p>{{ __('Press a category to see the cars inside it.') }}</p>
                    @if (collect($report['groups'])->contains('category', ''))
                        <p class="text-amber-700">{{ __('Cars under "No category" have none set. Open the car and choose its category in Cost & documents.') }}</p>
                    @endif
                @endif
                @if (collect($report['loose'])->contains('kind', 'limo'))
                    <p class="text-amber-700">{{ __('A limousine trip only counts for a car when the car is assigned to it in the bookings queue. Trips with no car are in their own row and count toward no car\'s target.') }}</p>
                @endif
            </div>
        </div>
    @endif
</div>
