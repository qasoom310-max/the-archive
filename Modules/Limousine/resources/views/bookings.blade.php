<div class="mx-auto max-w-7xl p-4 sm:p-6">
    <div class="mb-4 flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold text-chrome-900">{{ __('Bookings') }}</h1>
            <p class="text-sm text-chrome-500">{{ __('Trip bookings.') }}</p>
        </div>
        <a href="{{ url('/app/limousine/booking/new') }}" wire:navigate class="o-btn-primary">
            <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10 5a1 1 0 0 1 1 1v3h3a1 1 0 1 1 0 2h-3v3a1 1 0 1 1-2 0v-3H6a1 1 0 1 1 0-2h3V6a1 1 0 0 1 1-1Z"/></svg>
            {{ __('New booking') }}
        </a>
    </div>

    @php
        $tabs = [
            'all' => __('All'),
            'queue' => __('Queue'),
            'confirmed' => __('Confirmed'),
            'active' => __('Active'),
            'completed' => __('Completed'),
            'cancelled' => __('Cancelled'),
        ];
    @endphp
    <div class="mb-4 flex flex-wrap items-center gap-1 border-b border-chrome-200">
        @foreach ($tabs as $key => $label)
            @php $n = $key === 'all' ? $totalCount : (int) $counts->get($key, 0); @endphp
            <button wire:click="$set('tab', '{{ $key }}')"
                class="-mb-px flex items-center gap-1.5 border-b-2 px-4 py-2 text-sm font-medium {{ $tab === $key ? 'border-primary-600 text-primary-700' : 'border-transparent text-chrome-500 hover:text-chrome-800' }}">
                {{ $label }}
                <span class="rounded-full bg-chrome-100 px-1.5 text-[11px] text-chrome-500">{{ $n }}</span>
            </button>
        @endforeach
    </div>

    <div class="mb-4 flex flex-wrap items-end gap-3">
        <div>
            <label class="mb-1 block text-xs font-medium text-chrome-500">{{ __('Pick-up from') }}</label>
            <input type="date" wire:model.live="from" class="o-input text-sm">
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium text-chrome-500">{{ __('Pick-up to') }}</label>
            <input type="date" wire:model.live="to" class="o-input text-sm">
        </div>
        @if ($from !== '' || $to !== '')
            <button wire:click="$set('from', ''); $set('to', '')" class="text-sm text-chrome-500 hover:underline">{{ __('Clear') }}</button>
        @endif
    </div>

    <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-chrome-900/5">
        <table class="min-w-full divide-y divide-chrome-100 text-sm">
            <thead class="bg-chrome-50 text-xs font-semibold uppercase tracking-wide text-chrome-500">
                <tr>
                    <th class="px-4 py-2 text-start">{{ __('Reference') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Customer') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Route') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Pick-up') }}</th>
                    <th class="px-4 py-2 text-end">{{ __('Fare') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Status') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Payment') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-chrome-50">
                @forelse ($bookings as $b)
                    @php
                        $sb = [
                            'queue' => 'bg-amber-100 text-amber-700',
                            'confirmed' => 'bg-sky-100 text-sky-700',
                            'active' => 'bg-indigo-100 text-indigo-700',
                            'completed' => 'bg-emerald-100 text-emerald-700',
                            'cancelled' => 'bg-red-100 text-red-700',
                        ][$b->status] ?? 'bg-chrome-200 text-chrome-700';
                    @endphp
                    <tr wire:key="bk-{{ $b->id }}" class="cursor-pointer hover:bg-chrome-50"
                        onclick="window.location='{{ url('/app/limousine/booking/' . $b->id) }}'">
                        <td class="px-4 py-2 font-medium text-chrome-800">{{ $b->reference }}</td>
                        <td class="px-4 py-2 text-chrome-700">{{ $b->customer?->name ?? '—' }}</td>
                        <td class="px-4 py-2 text-chrome-600">{{ $b->pickupLocation?->name ?? '—' }} → {{ $b->dropoffLocation?->name ?? '—' }}</td>
                        <td class="px-4 py-2 text-chrome-600">{{ $b->pickup_at?->isoFormat('MMM D, h:mm A') ?? '—' }}</td>
                        <td class="px-4 py-2 text-end font-medium text-chrome-800">{{ \App\Erp\Views\ValueFormat::money($b->fare) }}</td>
                        <td class="px-4 py-2"><span class="rounded px-2 py-0.5 text-[11px] font-semibold uppercase {{ $sb }}">{{ __(ucfirst($b->status)) }}</span></td>
                        <td class="px-4 py-2"><span class="rounded px-2 py-0.5 text-[11px] font-semibold uppercase {{ $b->payment_status === 'paid' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' }}">{{ __(ucfirst($b->payment_status)) }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-10 text-center text-sm text-chrome-400">{{ __('No bookings found.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $bookings->links() }}</div>
</div>
