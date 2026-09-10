{{--
    Who pays this business the most over the last six months, companies and
    individuals ranked apart.

    Three things this learned the hard way:

    - SIX months, not twelve. Over a year the list filled with people who hired
      once and were last seen ten months ago, every row reading "too few jobs to
      know their rhythm" - a list of strangers, not a call sheet.
    - Companies and individuals are ranked APART. A handful of corporate
      accounts otherwise crowd out every individual and half the business never
      gets looked at.
    - Every row shows its own month-by-month record, so "going quiet" is
      something you can see rather than something you have to trust.

    Each row is still judged against THAT customer's own booking rhythm: a
    company that hires every three weeks and has been quiet for eight has a
    problem, while a family that hires once a year has not. One company-wide
    rule would be wrong about one of them every time.

    Owner only, like everything else in this band.

    Expects: $customers (from TopCustomers::forApp), $tile.
--}}
@php
    $money = static fn (float $v): string => \App\Erp\Views\ValueFormat::money($v);
    $badges = [
        'active' => ['label' => __('On track'), 'class' => 'bg-emerald-50 text-emerald-700 ring-emerald-100'],
        'slipping' => ['label' => __('Going quiet'), 'class' => 'bg-amber-50 text-amber-700 ring-amber-100'],
        'lost' => ['label' => __('Lost'), 'class' => 'bg-red-50 text-red-700 ring-red-100'],
        'new' => ['label' => __('New'), 'class' => 'bg-sky-50 text-sky-700 ring-sky-100'],
    ];
    $trends = [
        'up' => ['glyph' => '&uarr;', 'class' => 'text-emerald-600', 'label' => __('Spending more than last quarter')],
        'down' => ['glyph' => '&darr;', 'class' => 'text-red-600', 'label' => __('Spending less than last quarter')],
        'steady' => ['glyph' => '&rarr;', 'class' => 'text-chrome-400', 'label' => __('Spending about the same')],
        'flat' => ['glyph' => '·', 'class' => 'text-chrome-300', 'label' => __('Nothing in the last six months')],
    ];
    $tabs = [
        'company' => __('Companies'),
        'individual' => __('Individuals'),
    ];
    // Open on whichever group actually has people in it.
    $first = ($customers['groups']['company']['rows'] ?? []) !== [] ? 'company' : 'individual';
@endphp

<div class="mb-3 flex flex-wrap items-center gap-2">
    <h2 class="text-xs font-bold uppercase tracking-wider text-chrome-500">{{ __('Customers to focus on') }}</h2>
    <span class="rounded-full bg-chrome-100 px-2 py-0.5 text-[11px] font-medium text-chrome-500">{{ __('Owner only') }}</span>
    <span class="text-[11px] font-medium text-chrome-400">{{ __('Last :count months', ['count' => $customers['windowMonths']]) }}</span>
    <span class="h-px flex-1 bg-chrome-200"></span>
</div>

{{-- Tabs are client-side: both tables are already built, so switching costs
     no round trip and no second query. --}}
<div class="{{ $tile }} mb-8 hover:translate-y-0 hover:shadow-sm" x-data="{ group: '{{ $first }}' }">

    <div class="mb-4 flex items-center gap-1">
        @foreach ($tabs as $key => $label)
            <button type="button" x-on:click="group = '{{ $key }}'"
                class="rounded-lg px-3 py-1.5 text-sm font-semibold transition"
                x-bind:class="group === '{{ $key }}' ? 'bg-primary-400 text-chrome-900' : 'text-chrome-500 hover:bg-chrome-100'">
                {{ $label }}
                <span class="ms-1 text-xs font-medium opacity-70">{{ count($customers['groups'][$key]['rows'] ?? []) }}</span>
            </button>
        @endforeach
    </div>

    @foreach ($tabs as $key => $label)
        @php $grp = $customers['groups'][$key] ?? ['rows' => [], 'total' => 0.0, 'share' => null, 'quiet' => 0, 'atRisk' => 0.0]; @endphp
        <div x-show="group === '{{ $key }}'" x-cloak>
            @if ($grp['rows'] === [])
                <p class="py-8 text-center text-sm text-chrome-400">
                    {{ __('No :group paid for work in the last :count months.', ['group' => mb_strtolower($label), 'count' => $customers['windowMonths']]) }}
                </p>
            @else
                {{-- The headline is not "here are your best customers" but
                     "this much of your income has stopped calling". --}}
                <div class="mb-4 flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
                    <span class="text-chrome-600">
                        {{ __('These :count paid', ['count' => count($grp['rows'])]) }}
                        <strong class="text-chrome-900">{{ $money($grp['total']) }}</strong>@if ($grp['share'] !== null),
                            <strong class="text-chrome-900">{{ $grp['share'] }}%</strong> {{ __('of everything collected') }}@endif.
                    </span>
                    @if ($grp['quiet'] > 0)
                        <span class="rounded-lg bg-amber-50 px-3 py-1.5 text-xs font-semibold text-amber-800 ring-1 ring-amber-100">
                            {{ __(':count have gone quiet — :amount between them.', [
                                'count' => $grp['quiet'],
                                'amount' => $money($grp['atRisk']),
                            ]) }}
                        </span>
                    @else
                        <span class="rounded-lg bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-800 ring-1 ring-emerald-100">
                            {{ __('Every one of them is still booking on their usual rhythm.') }}
                        </span>
                    @endif
                </div>

                <div class="-mx-5 overflow-x-auto px-5">
                    <table class="w-full min-w-[58rem] text-sm">
                        <thead>
                            <tr class="border-b border-chrome-200 text-[11px] font-bold uppercase tracking-wider text-chrome-400">
                                <th class="w-8 py-2 text-start">#</th>
                                <th class="py-2 text-start">{{ __('Customer') }}</th>
                                @foreach ($customers['months'] as $month)
                                    <th class="px-1.5 py-2 text-end">{{ $month['label'] }}</th>
                                @endforeach
                                <th class="py-2 text-end">{{ __('Paid') }}</th>
                                <th class="py-2 text-end">{{ __('Jobs') }}</th>
                                <th class="py-2 text-end">{{ __('Average') }}</th>
                                <th class="py-2 text-start ps-4">{{ __('What to do') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-chrome-100">
                            @foreach ($grp['rows'] as $i => $row)
                                @php
                                    $badge = $badges[$row['status']] ?? $badges['new'];
                                    $trend = $trends[$row['trend']] ?? $trends['flat'];
                                @endphp
                                <tr class="{{ in_array($row['status'], ['slipping', 'lost'], true) ? 'bg-amber-50/40' : '' }}">
                                    <td class="py-2.5 text-xs font-bold text-chrome-300">{{ $i + 1 }}</td>
                                    <td class="py-2.5">
                                        <a href="{{ url($row['href']) }}" wire:navigate class="font-semibold text-chrome-900 hover:text-primary-700 hover:underline">
                                            {{ $row['name'] }}
                                        </a>
                                        <span class="ms-1.5 {{ $trend['class'] }}" title="{{ $trend['label'] }}">{!! $trend['glyph'] !!}</span>
                                    </td>
                                    @foreach ($customers['months'] as $month)
                                        @php $v = $row['months'][$month['key']] ?? 0.0; @endphp
                                        {{-- The month record is what turns "going quiet"
                                             from a claim into something visible. --}}
                                        <td class="px-1.5 py-2.5 text-end text-xs {{ $v > 0 ? 'font-semibold text-chrome-700' : 'text-chrome-300' }}">
                                            {{ $v > 0 ? number_format($v, 0) : '·' }}
                                        </td>
                                    @endforeach
                                    <td class="py-2.5 text-end font-bold text-chrome-900">
                                        {{ $money($row['paid']) }}
                                        <span class="block text-[11px] font-medium text-chrome-400">{{ $row['share'] }}%</span>
                                    </td>
                                    <td class="py-2.5 text-end text-chrome-600">{{ $row['jobs'] }}</td>
                                    <td class="py-2.5 text-end text-chrome-600">{{ $money($row['average']) }}</td>
                                    <td class="py-2.5 ps-4">
                                        <span class="inline-flex whitespace-nowrap rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide ring-1 {{ $badge['class'] }}">{{ $badge['label'] }}</span>
                                        <span class="mt-1 block text-[11px] leading-snug text-chrome-500">
                                            @if ($row['rhythm'] !== null && in_array($row['status'], ['slipping', 'lost'], true))
                                                {{ __('Books every :rhythm days — :over days overdue.', ['rhythm' => $row['rhythm'], 'over' => $row['overdueBy']]) }}
                                            @elseif ($row['rhythm'] !== null)
                                                {{ __('Books every :rhythm days.', ['rhythm' => $row['rhythm']]) }}
                                            @else
                                                {{ __('Too few jobs to know their rhythm yet.') }}
                                            @endif
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endforeach

    <p class="mt-3 text-[11px] leading-snug text-chrome-400">
        {{ __('Ranked by money collected between :from and :to, companies and individuals apart. "Going quiet" and "Lost" are measured against each customer\'s own booking rhythm, read from their whole history, not one rule for everybody.', [
            'from' => $customers['from'],
            'to' => $customers['to'],
        ]) }}
    </p>
</div>
