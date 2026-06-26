<div class="mx-auto max-w-5xl p-4 sm:p-6">
    <x-form-breadcrumb :parent="__('Customers')" :parent-url="url('/app/rental/customer')" :current="$customer?->name ?? __('New customer')" />

    {{-- Editable details (engine form — ACL-gated to admins / super-admins). --}}
    <livewire:views.form-view
        :model="\Modules\Rental\Models\RentalCustomer::class"
        model-key="rental.customer"
        :record-id="$customer?->id"
        title="{{ $customer ? 'Edit customer' : 'New customer' }}"
        :key="'rental-customer-form-'.($customer?->id ?? 'new')" />

    @if ($customer)
        {{-- ───────── At a glance ───────── --}}
        <div class="mt-6 grid gap-4 sm:grid-cols-3">
            @php
                $cards = [
                    ['label' => __('Rentals'), 'value' => $stats['rentals'], 'tint' => 'bg-primary-100 text-primary-700 ring-primary-400/25', 'icon' => '<path d="M3 9.5 4.2 6.6A2 2 0 0 1 6 5.5h8a2 2 0 0 1 1.8 1.1L17 9.5a2 2 0 0 1 1 1.7V13a1 1 0 0 1-1 1h-1a2 2 0 1 1-4 0H8a2 2 0 1 1-4 0H3a1 1 0 0 1-1-1v-1.8a2 2 0 0 1 1-1.7Z"/><circle cx="6.5" cy="14" r="1.5"/><circle cx="13.5" cy="14" r="1.5"/>'],
                    ['label' => __('Limousine trips'), 'value' => $stats['limo'], 'tint' => 'bg-indigo-50 text-indigo-600 ring-indigo-100', 'icon' => '<path fill-rule="evenodd" d="M5.75 2a.75.75 0 0 1 .75.75V4h7V2.75a.75.75 0 0 1 1.5 0V4h.25A2.75 2.75 0 0 1 18 6.75v8.5A2.75 2.75 0 0 1 15.25 18H4.75A2.75 2.75 0 0 1 2 15.25v-8.5A2.75 2.75 0 0 1 4.75 4H5V2.75A.75.75 0 0 1 5.75 2ZM3.5 8.5v6.75c0 .69.56 1.25 1.25 1.25h10.5c.69 0 1.25-.56 1.25-1.25V8.5h-13Z" clip-rule="evenodd"/>'],
                ];
            @endphp
            @foreach ($cards as $c)
                <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/[0.06]">
                    <div class="flex items-center justify-between">
                        <span class="flex size-9 items-center justify-center rounded-xl ring-1 {{ $c['tint'] }}">
                            <svg class="size-5" viewBox="0 0 20 20" fill="currentColor">{!! $c['icon'] !!}</svg>
                        </span>
                    </div>
                    <div class="mt-3 text-3xl font-bold tracking-tight text-chrome-900">{{ $c['value'] }}</div>
                    <div class="text-sm font-medium text-chrome-500">{{ $c['label'] }}</div>
                </div>
            @endforeach
            <div class="rounded-2xl bg-gradient-to-br from-emerald-600 to-emerald-700 p-5 shadow-sm">
                <div class="flex items-center justify-between">
                    <span class="flex size-9 items-center justify-center rounded-xl bg-white/15 text-white ring-1 ring-white/20">
                        <svg class="size-5" viewBox="0 0 20 20" fill="currentColor"><path d="M1 4.25C1 3.56 1.56 3 2.25 3h15.5c.69 0 1.25.56 1.25 1.25v8.5c0 .69-.56 1.25-1.25 1.25H2.25C1.56 14 1 13.44 1 12.75v-8.5ZM10 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z"/></svg>
                    </span>
                    <span class="rounded-full bg-white/15 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-white/90">{{ __('Paid') }}</span>
                </div>
                <div class="mt-3 text-2xl font-bold tracking-tight text-white">{{ \App\Erp\Views\ValueFormat::money($stats['spend']) }}</div>
                <div class="text-sm font-medium text-white/70">{{ __('Total spend') }}</div>
            </div>
        </div>

        {{-- ───────── Rental orders ───────── --}}
        <div class="mb-3 mt-8 flex items-center gap-2">
            <h2 class="text-xs font-bold uppercase tracking-wider text-chrome-500">{{ __('Rental orders') }}</h2>
            <span class="h-px flex-1 bg-chrome-200"></span>
        </div>
        @if ($orders->isNotEmpty())
            <div class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-chrome-900/[0.06]">
                <table class="min-w-full divide-y divide-chrome-100 text-sm">
                    <thead class="bg-chrome-50 text-xs font-semibold uppercase tracking-wide text-chrome-500">
                        <tr>
                            <th class="px-4 py-2 text-start">{{ __('Reference') }}</th>
                            <th class="px-4 py-2 text-start">{{ __('Car') }}</th>
                            <th class="px-4 py-2 text-start">{{ __('Pick-up') }}</th>
                            <th class="px-4 py-2 text-end">{{ __('Total') }}</th>
                            <th class="px-4 py-2 text-start">{{ __('Status') }}</th>
                            <th class="px-4 py-2 text-start">{{ __('Payment') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-chrome-50">
                        @foreach ($orders as $o)
                            @php
                                $sb = ['draft' => 'bg-chrome-200 text-chrome-700', 'active' => 'bg-sky-100 text-sky-700', 'closed' => 'bg-emerald-100 text-emerald-700', 'cancelled' => 'bg-red-100 text-red-700'][$o->state] ?? 'bg-chrome-200 text-chrome-700';
                            @endphp
                            <tr class="cursor-pointer hover:bg-chrome-50" onclick="window.location='{{ url('/app/rental/order/' . $o->id) }}'">
                                <td class="px-4 py-2 font-medium text-chrome-800">{{ $o->reference }}</td>
                                <td class="px-4 py-2 text-chrome-700">{{ $o->vehicle?->displayName() ?? '—' }}</td>
                                <td class="px-4 py-2 text-chrome-600">{{ $o->start_date?->isoFormat('MMM D, YYYY') ?? '—' }}</td>
                                <td class="px-4 py-2 text-end font-medium text-chrome-800">{{ \App\Erp\Views\ValueFormat::money($o->total) }}</td>
                                <td class="px-4 py-2"><span class="rounded px-2 py-0.5 text-[11px] font-semibold uppercase {{ $sb }}">{{ __(ucfirst($o->state)) }}</span></td>
                                <td class="px-4 py-2"><span class="rounded px-2 py-0.5 text-[11px] font-semibold uppercase {{ $o->payment_status === 'paid' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' }}">{{ __(ucfirst($o->payment_status)) }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="rounded-2xl bg-white px-4 py-8 text-center text-sm text-chrome-400 shadow-sm ring-1 ring-chrome-900/[0.06]">{{ __('No rental orders yet.') }}</p>
        @endif

        {{-- ───────── Limousine bookings ───────── --}}
        @if ($limoBookings->isNotEmpty())
            <div class="mb-3 mt-8 flex items-center gap-2">
                <h2 class="text-xs font-bold uppercase tracking-wider text-chrome-500">{{ __('Limousine bookings') }}</h2>
                <span class="h-px flex-1 bg-chrome-200"></span>
            </div>
            <div class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-chrome-900/[0.06]">
                <table class="min-w-full divide-y divide-chrome-100 text-sm">
                    <thead class="bg-chrome-50 text-xs font-semibold uppercase tracking-wide text-chrome-500">
                        <tr>
                            <th class="px-4 py-2 text-start">{{ __('Reference') }}</th>
                            <th class="px-4 py-2 text-start">{{ __('Pick-up') }}</th>
                            <th class="px-4 py-2 text-end">{{ __('Fare') }}</th>
                            <th class="px-4 py-2 text-start">{{ __('Status') }}</th>
                            <th class="px-4 py-2 text-start">{{ __('Payment') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-chrome-50">
                        @foreach ($limoBookings as $b)
                            @php
                                $lb = ['queue' => 'bg-amber-100 text-amber-700', 'confirmed' => 'bg-sky-100 text-sky-700', 'active' => 'bg-indigo-100 text-indigo-700', 'completed' => 'bg-emerald-100 text-emerald-700', 'cancelled' => 'bg-red-100 text-red-700'][$b->status] ?? 'bg-chrome-200 text-chrome-700';
                            @endphp
                            <tr class="cursor-pointer hover:bg-chrome-50" onclick="window.location='{{ url('/app/limousine/booking/' . $b->id) }}'">
                                <td class="px-4 py-2 font-medium text-chrome-800">{{ $b->reference }}</td>
                                <td class="px-4 py-2 text-chrome-600">{{ $b->pickup_at ? \Illuminate\Support\Carbon::parse($b->pickup_at)->isoFormat('MMM D, h:mm A') : '—' }}</td>
                                <td class="px-4 py-2 text-end font-medium text-chrome-800">{{ \App\Erp\Views\ValueFormat::money((float) $b->fare) }}</td>
                                <td class="px-4 py-2"><span class="rounded px-2 py-0.5 text-[11px] font-semibold uppercase {{ $lb }}">{{ __(ucfirst($b->status)) }}</span></td>
                                <td class="px-4 py-2"><span class="rounded px-2 py-0.5 text-[11px] font-semibold uppercase {{ $b->payment_status === 'paid' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' }}">{{ __(ucfirst($b->payment_status)) }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endif
</div>
