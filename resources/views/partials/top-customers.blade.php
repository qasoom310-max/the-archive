{{--
    Who pays this business the most, and which of them have stopped.

    A plain "biggest customers" list is a trophy cabinet - it names the same
    people every month and gives nobody anything to do. Every row is therefore
    judged against THAT customer's own booking rhythm: a company that hires
    every three weeks and has been quiet for eight is a problem, while a family
    that hires once a year and has been quiet for eight weeks is behaving
    normally. One company-wide "quiet for 60 days" rule would be wrong about
    one of them every single time.

    Owner only, like everything else in this band - it is a list of what each
    named customer pays.

    Expects: $customers (from TopCustomers::forApp), $tile.
--}}
@php
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
@endphp

<div class="mb-3 flex items-center gap-2">
    <h2 class="text-xs font-bold uppercase tracking-wider text-chrome-500">{{ __('Customers to focus on') }}</h2>
    <span class="rounded-full bg-chrome-100 px-2 py-0.5 text-[11px] font-medium text-chrome-500">{{ __('Owner only') }}</span>
    <span class="h-px flex-1 bg-chrome-200"></span>
</div>

<div class="{{ $tile }} mb-8 hover:translate-y-0 hover:shadow-sm">
    @if ($customers['rows'] === [])
        <p class="py-6 text-center text-sm text-chrome-400">
            {{ __('No paid work in the last :count months yet, so there is nobody to rank.', ['count' => $customers['months']]) }}
        </p>
    @else
        {{-- The headline is the point of the panel: not "here are your best
             customers" but "this much of your income is at risk right now". --}}
        <div class="mb-4 flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
            <span class="text-chrome-600">
                {{ __('These :count paid', ['count' => count($customers['rows'])]) }}
                <strong class="text-chrome-900">{{ \App\Erp\Views\ValueFormat::money($customers['total']) }}</strong>
                {{ __('in :count months', ['count' => $customers['months']]) }}@if ($customers['share'] !== null),
                    <strong class="text-chrome-900">{{ $customers['share'] }}%</strong> {{ __('of everything collected') }}@endif.
            </span>
            @if ($customers['quiet'] > 0)
                <span class="rounded-lg bg-amber-50 px-3 py-1.5 text-xs font-semibold text-amber-800 ring-1 ring-amber-100">
                    {{ __(':count have gone quiet — :amount a year between them.', [
                        'count' => $customers['quiet'],
                        'amount' => \App\Erp\Views\ValueFormat::money($customers['atRisk']),
                    ]) }}
                </span>
            @else
                <span class="rounded-lg bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-800 ring-1 ring-emerald-100">
                    {{ __('Every one of them is still booking on their usual rhythm.') }}
                </span>
            @endif
        </div>

        <div class="-mx-5 overflow-x-auto px-5">
            <table class="w-full min-w-[46rem] text-sm">
                <thead>
                    <tr class="border-b border-chrome-200 text-start text-[11px] font-bold uppercase tracking-wider text-chrome-400">
                        <th class="w-8 py-2 text-start">#</th>
                        <th class="py-2 text-start">{{ __('Customer') }}</th>
                        <th class="py-2 text-end">{{ __('Paid') }}</th>
                        <th class="py-2 text-end">{{ __('Jobs') }}</th>
                        <th class="py-2 text-end">{{ __('Average') }}</th>
                        <th class="py-2 text-end">{{ __('Last seen') }}</th>
                        <th class="py-2 text-start ps-4">{{ __('What to do') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-chrome-100">
                    @foreach ($customers['rows'] as $i => $row)
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
                            <td class="py-2.5 text-end font-bold text-chrome-900">
                                {{ \App\Erp\Views\ValueFormat::money($row['paid']) }}
                                <span class="block text-[11px] font-medium text-chrome-400">{{ $row['share'] }}%</span>
                            </td>
                            <td class="py-2.5 text-end text-chrome-600">{{ $row['jobs'] }}</td>
                            <td class="py-2.5 text-end text-chrome-600">{{ \App\Erp\Views\ValueFormat::money($row['average']) }}</td>
                            <td class="py-2.5 text-end text-chrome-600">
                                @if ($row['daysSince'] === null)
                                    —
                                @else
                                    {{ trans_choice('{0} Today|{1} :count day ago|[2,*] :count days ago', $row['daysSince'], ['count' => $row['daysSince']]) }}
                                @endif
                            </td>
                            <td class="py-2.5 ps-4">
                                <span class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide ring-1 {{ $badge['class'] }}">{{ $badge['label'] }}</span>
                                <span class="mt-1 block text-[11px] leading-snug text-chrome-500">
                                    @if ($row['rhythm'] !== null && in_array($row['status'], ['slipping', 'lost'], true))
                                        {{-- The two numbers together are the whole
                                             argument for picking up the phone. --}}
                                        {{ __('Books every :rhythm days — :over days overdue.', [
                                            'rhythm' => $row['rhythm'],
                                            'over' => $row['overdueBy'],
                                        ]) }}
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

        <p class="mt-3 text-[11px] leading-snug text-chrome-400">
            {{ __('Ranked by money collected in the last :count months. "Going quiet" and "Lost" are measured against each customer\'s own booking rhythm, not one rule for everybody.', ['count' => $customers['months']]) }}
        </p>
    @endif
</div>
