<div class="mx-auto max-w-7xl p-4 sm:p-6">
    <div class="mb-4">
        <h1 class="text-xl font-bold text-chrome-900">{{ __('Reports') }}</h1>
        <p class="text-sm text-chrome-500">{{ __('Rental performance over a date range.') }}</p>
    </div>

    {{-- Tabs --}}
    @php $tabs = ['summary' => __('Rental report'), 'orders' => __('Orders'), 'vehicles' => __('Cars'), 'customers' => __('Customers')]; @endphp
    <div class="mb-4 flex flex-wrap items-center gap-1 border-b border-chrome-200">
        @foreach ($tabs as $key => $label)
            <button wire:click="$set('tab', '{{ $key }}')"
                class="-mb-px border-b-2 px-4 py-2 text-sm font-medium {{ $tab === $key ? 'border-primary-600 text-primary-700' : 'border-transparent text-chrome-500 hover:text-chrome-800' }}">
                {{ $label }}
            </button>
        @endforeach
    </div>

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
        <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-chrome-900/5">
            <table class="min-w-full divide-y divide-chrome-100 text-sm">
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
        <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-chrome-900/5">
            <table class="min-w-full divide-y divide-chrome-100 text-sm">
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

    {{-- ── Customers ── --}}
    @if ($tab === 'customers')
        <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-chrome-900/5">
            <table class="min-w-full divide-y divide-chrome-100 text-sm">
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
