<div class="mx-auto max-w-7xl p-4 sm:p-6">
    <x-page-header :title="__('Reports')" :subtitle="__('Limousine performance over a date range.')" icon="chart" accent="indigo" />

    @php $tabs = ['summary' => __('Summary'), 'bookings' => __('Bookings'), 'customers' => __('Customers')]; @endphp
    <div class="mb-4 flex flex-wrap items-center gap-1 border-b border-chrome-200">
        @foreach ($tabs as $key => $label)
            <button wire:click="$set('tab', '{{ $key }}')"
                class="-mb-px border-b-2 px-4 py-2 text-sm font-medium {{ $tab === $key ? 'border-primary-600 text-primary-700' : 'border-transparent text-chrome-500 hover:text-chrome-800' }}">
                {{ $label }}
            </button>
        @endforeach
    </div>

    <div class="mb-6 flex flex-wrap items-end gap-3">
        <div>
            <label class="mb-1 block text-xs font-medium text-chrome-500">{{ __('From') }}</label>
            <input type="date" wire:model.live="from" class="o-input text-sm">
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium text-chrome-500">{{ __('To') }}</label>
            <input type="date" wire:model.live="to" class="o-input text-sm">
        </div>
        @if ($tab === 'bookings')
            <a href="{{ url('/app/limousine/reports/bookings/export?from=' . $from . '&to=' . $to) }}" class="o-btn-ghost text-sm">{{ __('Export CSV') }}</a>
        @endif
    </div>

    @if ($tab === 'summary')
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @php
                $cards = [
                    ['label' => __('Bookings'), 'value' => $summary['bookingsCount'], 'money' => false],
                    ['label' => __('Fare value'), 'value' => $summary['fareValue'], 'money' => true],
                    ['label' => __('Completed Trips'), 'value' => $summary['completed'], 'money' => false],
                    ['label' => __('Collected'), 'value' => $summary['collected'], 'money' => true],
                    ['label' => __('Outstanding'), 'value' => $summary['outstanding'], 'money' => true],
                    ['label' => __('Expenses'), 'value' => $summary['expenses'], 'money' => true],
                    ['label' => __('Net'), 'value' => $summary['net'], 'money' => true, 'net' => true],
                ];
            @endphp
            @foreach ($cards as $c)
                <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5">
                    <div class="text-sm font-medium text-chrome-600">{{ $c['label'] }}</div>
                    <div class="mt-2 text-2xl font-bold {{ ($c['net'] ?? false) && $c['value'] < 0 ? 'text-red-600' : 'text-chrome-800' }}">
                        {{ $c['money'] ? \App\Erp\Views\ValueFormat::money($c['value']) : $c['value'] }}
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    @if ($tab === 'bookings')
        <div class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-chrome-900/[0.06]">
            <table class="min-w-full divide-y divide-chrome-100 text-sm">
                <thead class="bg-chrome-50 text-xs font-semibold uppercase tracking-wide text-chrome-500">
                    <tr>
                        <th class="px-4 py-2 text-start">{{ __('Reference') }}</th>
                        <th class="px-4 py-2 text-start">{{ __('Customer') }}</th>
                        <th class="px-4 py-2 text-start">{{ __('Route') }}</th>
                        <th class="px-4 py-2 text-start">{{ __('Pick-up') }}</th>
                        <th class="px-4 py-2 text-start">{{ __('Status') }}</th>
                        <th class="px-4 py-2 text-end">{{ __('Fare') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-chrome-50">
                    @forelse ($bookings as $b)
                        <tr>
                            <td class="px-4 py-2 font-medium text-chrome-800">{{ $b->reference }}</td>
                            <td class="px-4 py-2 text-chrome-700">{{ $b->customer?->name ?? '—' }}</td>
                            <td class="px-4 py-2 text-chrome-600">{{ $b->pickupLocation?->name ?? '—' }} → {{ $b->dropoffLocation?->name ?? '—' }}</td>
                            <td class="px-4 py-2 text-chrome-600">{{ $b->pickup_at?->isoFormat('MMM D, h:mm A') ?? '—' }}</td>
                            <td class="px-4 py-2 text-chrome-600">{{ __(ucfirst($b->status)) }}</td>
                            <td class="px-4 py-2 text-end font-medium text-chrome-800">{{ \App\Erp\Views\ValueFormat::money($b->fare) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-10 text-center text-sm text-chrome-400">{{ __('No bookings in this range.') }}</td></tr>
                    @endforelse
                </tbody>
                @if ($bookings->isNotEmpty())
                    <tfoot class="bg-chrome-50 text-sm font-semibold text-chrome-800">
                        <tr>
                            <td class="px-4 py-2" colspan="5">{{ __('Total') }} ({{ $bookings->count() }})</td>
                            <td class="px-4 py-2 text-end">{{ \App\Erp\Views\ValueFormat::money($bookings->sum('fare')) }}</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    @endif

    @if ($tab === 'customers')
        <div class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-chrome-900/[0.06]">
            <table class="min-w-full divide-y divide-chrome-100 text-sm">
                <thead class="bg-chrome-50 text-xs font-semibold uppercase tracking-wide text-chrome-500">
                    <tr>
                        <th class="px-4 py-2 text-start">{{ __('Customer') }}</th>
                        <th class="px-4 py-2 text-start">{{ __('Phone') }}</th>
                        <th class="px-4 py-2 text-end">{{ __('Bookings') }}</th>
                        <th class="px-4 py-2 text-end">{{ __('Revenue') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-chrome-50">
                    @forelse ($customers as $row)
                        <tr>
                            <td class="px-4 py-2 font-medium text-chrome-800">{{ $row->customer?->name ?? '—' }}</td>
                            <td class="px-4 py-2 text-chrome-600">{{ $row->customer?->phone ?? '—' }}</td>
                            <td class="px-4 py-2 text-end text-chrome-700">{{ (int) $row->bookings_count }}</td>
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
