<div class="mx-auto max-w-7xl p-4 sm:p-6">
    <x-page-header :title="__('Reports')" :subtitle="__('Rental performance over a date range.')" icon="chart" accent="primary" />

    {{-- Tabs --}}
    @php $tabs = ['summary' => __('Rental report'), 'orders' => __('Orders'), 'vehicles' => __('Cars'), 'targets' => __('Targets'), 'customers' => __('Customers')]; @endphp
    <div class="mb-4 flex flex-wrap items-center gap-1 border-b border-chrome-200">
        @foreach ($tabs as $key => $label)
            <button wire:click="$set('tab', '{{ $key }}')"
                class="-mb-px border-b-2 px-4 py-2 text-sm font-medium {{ $tab === $key ? 'border-primary-600 text-primary-700' : 'border-transparent text-chrome-500 hover:text-chrome-800' }}">
                {{ $label }}
            </button>
        @endforeach
    </div>

    {{-- Targets lens picks a month / year; every other lens uses the date range. --}}
    @if ($tab === 'targets')
        <div class="mb-6 flex flex-wrap items-end gap-3">
            <div>
                <label class="mb-1 block text-xs font-medium text-chrome-500">{{ __('Year') }}</label>
                <select wire:model.live="targetYear" class="o-input text-sm">
                    @foreach ($targets['years'] as $y)
                        <option value="{{ $y }}">{{ $y }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-chrome-500">{{ __('Period') }}</label>
                <select wire:model.live="targetMonth" class="o-input text-sm">
                    <option value="0">{{ __('Whole year') }}</option>
                    @foreach (\Modules\Rental\Support\SalesReport::MONTHS as $num => $abbr)
                        <option value="{{ $num }}">{{ __($abbr) }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    @else
        {{-- Shared date range (by pick-up date) --}}
        <div class="mb-6 flex flex-wrap items-end gap-3">
            <div>
                <label class="mb-1 block text-xs font-medium text-chrome-500">{{ __('From') }}</label>
                <input type="date" wire:model.live="from" class="o-input text-sm">
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-chrome-500">{{ __('To') }}</label>
                <input type="date" wire:model.live="to" class="o-input text-sm">
            </div>
            @if ($tab === 'orders')
                <a href="{{ url('/app/rental/reports/orders/export?from=' . $from . '&to=' . $to) }}"
                    class="o-btn-ghost text-sm">{{ __('Export CSV') }}</a>
            @endif
        </div>
    @endif

    {{-- ── Summary / "Rental report" ── --}}
    @if ($tab === 'summary')
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @php
                $cards = [
                    ['label' => __('Orders'), 'value' => $summary['ordersCount'], 'money' => false],
                    ['label' => __('Orders value'), 'value' => $summary['ordersRevenue'], 'money' => true],
                    ['label' => __('Active now'), 'value' => $summary['activeNow'], 'money' => false],
                    ['label' => __('Invoiced'), 'value' => $summary['invoiced'], 'money' => true],
                    ['label' => __('Collected'), 'value' => $summary['collected'], 'money' => true],
                    ['label' => __('Outstanding'), 'value' => $summary['outstanding'], 'money' => true],
                    ['label' => __('Maintenance spend'), 'value' => $summary['maintenanceSpend'], 'money' => true],
                ];
            @endphp
            @foreach ($cards as $c)
                <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5">
                    <div class="text-sm font-medium text-chrome-600">{{ $c['label'] }}</div>
                    <div class="mt-2 text-2xl font-bold text-chrome-800">
                        {{ $c['money'] ? \App\Erp\Views\ValueFormat::money($c['value']) : $c['value'] }}
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- ── Orders ── --}}
    @if ($tab === 'orders')
        <div class="overflow-x-auto rounded-2xl bg-white shadow-sm ring-1 ring-chrome-900/[0.06]">
            <table class="w-full min-w-[640px] divide-y divide-chrome-100 text-sm">
                <thead class="bg-chrome-50 text-xs font-semibold uppercase tracking-wide text-chrome-500">
                    <tr>
                        <th class="px-4 py-2 text-start">{{ __('Reference') }}</th>
                        <th class="px-4 py-2 text-start">{{ __('Customer') }}</th>
                        <th class="px-4 py-2 text-start">{{ __('Car') }}</th>
                        <th class="px-4 py-2 text-start">{{ __('Pick-up') }}</th>
                        <th class="px-4 py-2 text-start">{{ __('Status') }}</th>
                        <th class="px-4 py-2 text-end">{{ __('Total') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-chrome-50">
                    @forelse ($orders as $o)
                        <tr>
                            <td class="px-4 py-2 font-medium text-chrome-800">{{ $o->reference }}</td>
                            <td class="px-4 py-2 text-chrome-700">{{ $o->customer?->name ?? '—' }}</td>
                            <td class="px-4 py-2 text-chrome-700">{{ $o->vehicle?->displayName() ?? '—' }}</td>
                            <td class="px-4 py-2 text-chrome-600">{{ $o->start_date?->isoFormat('MMM D, YYYY') ?? '—' }}</td>
                            <td class="px-4 py-2 text-chrome-600">{{ __(ucfirst($o->state)) }}</td>
                            <td class="px-4 py-2 text-end font-medium text-chrome-800">{{ \App\Erp\Views\ValueFormat::money($o->total) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-10 text-center text-sm text-chrome-400">{{ __('No orders in this range.') }}</td></tr>
                    @endforelse
                </tbody>
                @if ($orders->isNotEmpty())
                    <tfoot class="bg-chrome-50 text-sm font-semibold text-chrome-800">
                        <tr>
                            <td class="px-4 py-2" colspan="5">{{ __('Total') }} ({{ $orders->count() }})</td>
                            <td class="px-4 py-2 text-end">{{ \App\Erp\Views\ValueFormat::money($orders->sum('total')) }}</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    @endif

    {{-- ── Vehicles ── --}}
    @if ($tab === 'vehicles')
        <div class="overflow-x-auto rounded-2xl bg-white shadow-sm ring-1 ring-chrome-900/[0.06]">
            <table class="w-full min-w-[640px] divide-y divide-chrome-100 text-sm">
                <thead class="bg-chrome-50 text-xs font-semibold uppercase tracking-wide text-chrome-500">
                    <tr>
                        <th class="px-4 py-2 text-start">{{ __('Car') }}</th>
                        <th class="px-4 py-2 text-start">{{ __('Current status') }}</th>
                        <th class="px-4 py-2 text-end">{{ __('Orders') }}</th>
                        <th class="px-4 py-2 text-end">{{ __('Days out') }}</th>
                        <th class="px-4 py-2 text-end">{{ __('Revenue') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-chrome-50">
                    @forelse ($vehicles as $v)
                        <tr>
                            <td class="px-4 py-2 font-medium text-chrome-800">{{ $v['name'] }}</td>
                            <td class="px-4 py-2 text-chrome-600">{{ __(ucfirst($v['status'])) }}</td>
                            <td class="px-4 py-2 text-end text-chrome-700">{{ $v['orders'] }}</td>
                            <td class="px-4 py-2 text-end text-chrome-700">{{ $v['days'] }}</td>
                            <td class="px-4 py-2 text-end font-medium text-chrome-800">{{ \App\Erp\Views\ValueFormat::money($v['revenue']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-10 text-center text-sm text-chrome-400">{{ __('No vehicles yet.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

    {{-- ── Targets ── --}}
    @if ($tab === 'targets')
        @php $periodLabel = $targets['wholeYear'] ? $targets['year'] : (__(\Modules\Rental\Support\SalesReport::MONTHS[$targets['month']] ?? '') . ' ' . $targets['year']); @endphp

        {{-- Headline KPIs for the selected year (our cars). --}}
        @php $k = $targets['kpi']; @endphp
        <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/5">
                <div class="text-xs font-medium uppercase tracking-wide text-chrome-400">{{ $k['isCurrentYear'] ? __('Revenue (year to date)') : __('Revenue (year)') }}</div>
                <div class="mt-1 text-2xl font-bold text-chrome-800">{{ \App\Erp\Views\ValueFormat::money($k['total']) }}</div>
                <div class="mt-0.5 text-xs text-chrome-400">{{ __('Avg :money / month', ['money' => \App\Erp\Views\ValueFormat::money($k['avg'])]) }}</div>
            </div>
            <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/5">
                <div class="text-xs font-medium uppercase tracking-wide text-chrome-400">{{ __('Target attainment') }}</div>
                @if ($k['attainment'] !== null)
                    <div class="mt-1 text-2xl font-bold {{ $k['attainment'] >= 100 ? 'text-emerald-600' : 'text-amber-600' }}">{{ (int) $k['attainment'] }}%</div>
                    <div class="mt-0.5 text-xs text-chrome-400">{{ __('of target so far') }}</div>
                @else
                    <div class="mt-1 text-2xl font-bold text-chrome-300">—</div>
                    <div class="mt-0.5 text-xs text-chrome-400">{{ __('no targets set') }}</div>
                @endif
            </div>
            <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/5">
                <div class="text-xs font-medium uppercase tracking-wide text-chrome-400">{{ __('vs :year', ['year' => $targets['year'] - 1]) }}</div>
                @if ($k['yoy'] !== null)
                    <div class="mt-1 flex items-center gap-1 text-2xl font-bold {{ $k['yoy'] >= 0 ? 'text-emerald-600' : 'text-red-600' }}">
                        <span>{{ $k['yoy'] >= 0 ? '▲' : '▼' }}</span><span>{{ abs((int) $k['yoy']) }}%</span>
                    </div>
                    <div class="mt-0.5 text-xs text-chrome-400">{{ __('was :money', ['money' => \App\Erp\Views\ValueFormat::money($k['prevTotal'])]) }}</div>
                @else
                    <div class="mt-1 text-2xl font-bold text-chrome-300">—</div>
                    <div class="mt-0.5 text-xs text-chrome-400">{{ __('no prior-year data') }}</div>
                @endif
            </div>
            <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/5">
                @if ($k['isCurrentYear'])
                    <div class="text-xs font-medium uppercase tracking-wide text-chrome-400">{{ __('Projected year-end') }}</div>
                    <div class="mt-1 text-2xl font-bold text-chrome-800">{{ \App\Erp\Views\ValueFormat::money($k['projected']) }}</div>
                    <div class="mt-0.5 text-xs text-chrome-400">{{ __('at the current run-rate') }}</div>
                @else
                    <div class="text-xs font-medium uppercase tracking-wide text-chrome-400">{{ __('Best month') }}</div>
                    <div class="mt-1 text-2xl font-bold text-chrome-800">{{ $k['bestMonth'] ? __(\Modules\Rental\Support\SalesReport::MONTHS[$k['bestMonth']]) : '—' }}</div>
                    <div class="mt-0.5 text-xs text-chrome-400">{{ \App\Erp\Views\ValueFormat::money($k['bestValue']) }}</div>
                @endif
            </div>
        </div>

        {{-- Year-at-a-glance: fleet revenue per month vs the monthly fleet target,
             with last year overlaid as a line. Click a bar to drill the table. --}}
        @php
            $monthly = $targets['monthly'];
            $prevMonthly = $targets['prevMonthly'];
            $fleetTarget = (float) $targets['fleetTarget'];
            $peak = max($fleetTarget, max($monthly), max($prevMonthly), 1);
            $scale = $peak * 1.15; // headroom so the target line isn't at the very top
            $hasPrev = array_sum($prevMonthly) > 0;
            // Polyline points for last year's line over a 0..12 × 0..100 viewBox.
            $prevPts = [];
            foreach (\Modules\Rental\Support\SalesReport::MONTHS as $pm => $pa) {
                $prevPts[] = ($pm - 0.5) . ',' . (100 - ($prevMonthly[$pm] ?? 0) / $scale * 100);
            }
        @endphp
        <div class="mb-4 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/[0.06] sm:p-5">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h3 class="text-sm font-semibold text-chrome-800">{{ __('Revenue by month') }} · {{ $targets['year'] }}</h3>
                <div class="flex items-center gap-4 text-xs text-chrome-500">
                    <span class="flex items-center gap-1.5"><span class="inline-block size-2.5 rounded-sm bg-emerald-500"></span>{{ __('Hit target') }}</span>
                    <span class="flex items-center gap-1.5"><span class="inline-block size-2.5 rounded-sm bg-amber-400"></span>{{ __('Below target') }}</span>
                    @if ($fleetTarget > 0)
                        <span class="flex items-center gap-1.5"><span class="inline-block h-0 w-4 border-t-2 border-dashed border-chrome-400"></span>{{ __('Target') }} {{ \App\Erp\Views\ValueFormat::money($fleetTarget) }}</span>
                    @endif
                    @if ($hasPrev)
                        <span class="flex items-center gap-1.5"><span class="inline-block h-0 w-4 border-t-2 border-sky-400"></span>{{ __(':year', ['year' => $targets['year'] - 1]) }}</span>
                    @endif
                </div>
            </div>
            <div class="relative h-44">
                {{-- Monthly fleet target reference line --}}
                @if ($fleetTarget > 0)
                    <div class="pointer-events-none absolute inset-x-0 z-10 border-t-2 border-dashed border-chrome-400/70" style="bottom: {{ $fleetTarget / $scale * 100 }}%"></div>
                @endif
                {{-- Last year overlaid as a line (same fleet, prior year). --}}
                @if ($hasPrev)
                    <svg class="pointer-events-none absolute inset-0 z-20 h-full w-full" viewBox="0 0 12 100" preserveAspectRatio="none" aria-hidden="true">
                        <polyline points="{{ implode(' ', $prevPts) }}" fill="none" stroke="#38bdf8" stroke-width="0.6" vector-effect="non-scaling-stroke" stroke-linejoin="round" />
                    </svg>
                @endif
                <div class="flex h-full items-end gap-1 sm:gap-2">
                    @foreach (\Modules\Rental\Support\SalesReport::MONTHS as $num => $abbr)
                        @php
                            $val = (float) ($monthly[$num] ?? 0.0);
                            $hgt = $val > 0 ? max(2, $val / $scale * 100) : 0;
                            $hit = $fleetTarget > 0 && $val >= $fleetTarget;
                            $isSel = ! $targets['wholeYear'] && $targets['month'] === $num;
                        @endphp
                        <button type="button" wire:click="$set('targetMonth', {{ $num }})"
                            class="group relative flex h-full flex-1 flex-col justify-end rounded-t hover:bg-chrome-50"
                            title="{{ __($abbr) }} {{ $targets['year'] }} — {{ \App\Erp\Views\ValueFormat::money($val) }}">
                            <span class="mb-1 text-center text-[10px] font-medium text-chrome-400 opacity-0 transition group-hover:opacity-100">{{ \App\Erp\Views\ValueFormat::money($val) }}</span>
                            <div class="w-full rounded-t {{ $hit ? 'bg-emerald-500' : ($val > 0 ? 'bg-amber-400' : 'bg-chrome-100') }} {{ $isSel ? 'ring-2 ring-primary-500 ring-offset-1' : '' }}"
                                style="height: {{ $hgt }}%"></div>
                        </button>
                    @endforeach
                </div>
            </div>
            <div class="mt-1 flex gap-1 sm:gap-2">
                @foreach (\Modules\Rental\Support\SalesReport::MONTHS as $num => $abbr)
                    @php $isSel = ! $targets['wholeYear'] && $targets['month'] === $num; @endphp
                    <div class="flex-1 text-center text-[11px] {{ $isSel ? 'font-bold text-primary-700' : 'text-chrome-500' }}">{{ __($abbr) }}</div>
                @endforeach
            </div>
        </div>
        <div class="mb-4 rounded-xl bg-white p-4 text-sm shadow-sm ring-1 ring-chrome-900/5">
            @if ($targets['targetCount'] > 0)
                <span class="font-semibold text-chrome-800">{{ $targets['achievedCount'] }} / {{ $targets['targetCount'] }}</span>
                <span class="text-chrome-500">{{ __('cars hit their target for :period', ['period' => $periodLabel]) }}</span>
            @else
                <span class="text-chrome-500">{{ __('No car has a monthly target set yet — set one on the car page.') }}</span>
            @endif
        </div>
        <div class="overflow-x-auto rounded-2xl bg-white shadow-sm ring-1 ring-chrome-900/[0.06]">
            <table class="w-full min-w-[640px] divide-y divide-chrome-100 text-sm">
                <thead class="bg-chrome-50 text-xs font-semibold uppercase tracking-wide text-chrome-500">
                    <tr>
                        <th class="px-4 py-2 text-start">{{ __('Car') }}</th>
                        <th class="px-4 py-2 text-end">{{ $targets['wholeYear'] ? __('Target (year)') : __('Target (month)') }}</th>
                        <th class="px-4 py-2 text-end">{{ __('Revenue') }}</th>
                        <th class="px-4 py-2 text-end">{{ __('Achieved') }}</th>
                        <th class="px-4 py-2 text-start">{{ __('Status') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-chrome-50">
                    @forelse ($targets['rows'] as $r)
                        <tr class="{{ $r['hasTarget'] && ! $r['achieved'] ? 'bg-red-50/40' : '' }}">
                            <td class="px-4 py-2 font-medium text-chrome-800">{{ $r['name'] }}</td>
                            <td class="px-4 py-2 text-end text-chrome-600">
                                @if ($r['hasTarget']){{ \App\Erp\Views\ValueFormat::money($r['expected']) }}@else<span class="text-chrome-300">—</span>@endif
                            </td>
                            <td class="px-4 py-2 text-end font-medium text-chrome-800">{{ \App\Erp\Views\ValueFormat::money($r['revenue']) }}</td>
                            <td class="px-4 py-2 text-end">
                                @if ($r['pct'] !== null)
                                    <div class="flex items-center justify-end gap-2">
                                        <div class="hidden h-1.5 w-20 overflow-hidden rounded-full bg-chrome-100 sm:block">
                                            <div class="h-full rounded-full {{ $r['achieved'] ? 'bg-emerald-500' : 'bg-amber-400' }}" style="width: {{ min(100, (int) $r['pct']) }}%"></div>
                                        </div>
                                        <span class="tabular-nums {{ $r['achieved'] ? 'text-emerald-600' : 'text-amber-600' }}">{{ (int) $r['pct'] }}%</span>
                                    </div>
                                @else
                                    <span class="text-chrome-300">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-2">
                                @if (! $r['hasTarget'])
                                    <span class="rounded px-2 py-0.5 text-[11px] font-semibold uppercase text-chrome-400">{{ __('No target') }}</span>
                                @elseif ($r['achieved'])
                                    <span class="rounded bg-emerald-100 px-2 py-0.5 text-[11px] font-semibold uppercase text-emerald-700">{{ __('Achieved') }}</span>
                                @else
                                    <span class="rounded bg-red-100 px-2 py-0.5 text-[11px] font-semibold uppercase text-red-700">{{ __('Missed') }}</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-10 text-center text-sm text-chrome-400">{{ __('No cars yet.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

    {{-- ── Customers ── --}}
    @if ($tab === 'customers')
        <div class="overflow-x-auto rounded-2xl bg-white shadow-sm ring-1 ring-chrome-900/[0.06]">
            <table class="w-full min-w-[640px] divide-y divide-chrome-100 text-sm">
                <thead class="bg-chrome-50 text-xs font-semibold uppercase tracking-wide text-chrome-500">
                    <tr>
                        <th class="px-4 py-2 text-start">{{ __('Customer') }}</th>
                        <th class="px-4 py-2 text-start">{{ __('Phone') }}</th>
                        <th class="px-4 py-2 text-end">{{ __('Orders') }}</th>
                        <th class="px-4 py-2 text-end">{{ __('Revenue') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-chrome-50">
                    @forelse ($customers as $row)
                        <tr>
                            <td class="px-4 py-2 font-medium text-chrome-800">{{ $row->customer?->name ?? '—' }}</td>
                            <td class="px-4 py-2 text-chrome-600">{{ $row->customer?->phone ?? '—' }}</td>
                            <td class="px-4 py-2 text-end text-chrome-700">{{ (int) $row->orders_count }}</td>
                            <td class="px-4 py-2 text-end font-medium text-chrome-800">{{ \App\Erp\Views\ValueFormat::money((float) $row->revenue) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-10 text-center text-sm text-chrome-400">{{ __('No customers in this range.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif
</div>
