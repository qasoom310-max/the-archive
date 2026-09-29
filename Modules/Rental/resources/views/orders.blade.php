<div class="mx-auto max-w-7xl p-4 sm:p-6">
    <x-page-header :title="__('Orders')" :subtitle="__('Rental contracts.')" icon="doc" accent="primary">
        <x-slot:actions>
            @if ($canManage ?? false)
                <button type="button" onclick="document.getElementById('import-orders').classList.toggle('hidden')" class="o-btn-ghost">{{ __('Import') }}</button>
            @endif
            <a href="{{ url('/app/rental/order/new') }}" wire:navigate class="o-btn-primary">
                <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10 5a1 1 0 0 1 1 1v3h3a1 1 0 1 1 0 2h-3v3a1 1 0 1 1-2 0v-3H6a1 1 0 1 1 0-2h3V6a1 1 0 0 1 1-1Z"/></svg>
                {{ __('New order') }}
            </a>
        </x-slot:actions>
    </x-page-header>

    {{-- Import orders from a CSV (managers). Direct POST — Hostinger-safe. The
         expected columns are the same shape this screen's own export prints,
         so a sheet pulled off this page's own download needs no re-typing to
         come back in. Every row lands as settled history at the figures the
         old system recorded. --}}
    @if ($canManage ?? false)
        <div id="import-orders" class="mb-4 {{ $errors->any() ? '' : 'hidden' }} rounded-2xl border border-dashed border-chrome-300 bg-white p-4">
            <h3 class="mb-1 text-sm font-semibold text-chrome-800">{{ __('Import orders (CSV)') }}</h3>
            <p class="mb-3 text-xs text-chrome-500">{{ __('Columns: Reference, Customer, Car, Pick-up, Return, Total, Received, Status, Payment. Other columns are ignored. The same customer, pick-up date and total seen before is skipped.') }}</p>
            <form method="POST" action="{{ url('/app/rental/order/import') }}" enctype="multipart/form-data" class="flex flex-wrap items-end gap-3">
                @csrf
                <input type="file" name="file" accept=".csv,text/csv,text/plain" required class="text-sm">
                <button type="submit" class="o-btn-primary text-sm">{{ __('Import') }}</button>
            </form>
            @error('file')<p class="mt-2 text-xs font-medium text-red-600">{{ $message }}</p>@enderror
            @if (session('toast'))
                <p class="mt-2 text-xs font-medium text-emerald-600">{{ session('toast') }}</p>
            @endif
        </div>
    @endif

    {{-- Status tabs --}}
    @php
        $tabs = [
            'all' => __('All'),
            'draft' => __('Reservation'),
            'active' => __('Active'),
            'closed' => __('Closed'),
            'unpaid' => __('Unpaid'),
            'cancelled' => __('Cancelled'),
        ];
    @endphp
    <div class="mb-4 flex flex-wrap items-center gap-1 border-b border-chrome-200">
        @foreach ($tabs as $key => $label)
            @php $n = match ($key) { 'all' => $totalCount, 'unpaid' => $unpaidCount, default => (int) $counts->get($key, 0) }; @endphp
            <button wire:click="$set('tab', '{{ $key }}')"
                class="-mb-px flex items-center gap-1.5 border-b-2 px-4 py-2 text-sm font-medium {{ $tab === $key ? 'border-primary-600 text-primary-700' : 'border-transparent text-chrome-500 hover:text-chrome-800' }}">
                {{ $label }}
                <span class="rounded-full {{ $key === 'unpaid' && $n > 0 ? 'bg-red-100 text-red-600' : 'bg-chrome-100 text-chrome-500' }} px-1.5 text-[11px]">{{ $n }}</span>
            </button>
        @endforeach
    </div>

    {{-- Search + date range (by pick-up date) --}}
    <div class="mb-4 flex flex-wrap items-end gap-3">
        <div class="min-w-[16rem] flex-1">
            <label class="mb-1 block text-xs font-medium text-chrome-500">{{ __('Search') }}</label>
            <div class="relative">
                <svg class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-chrome-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 1 0 3.4 9.82l3.64 3.64a1 1 0 0 0 1.42-1.42l-3.64-3.64A5.5 5.5 0 0 0 9 3.5ZM5.5 9a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0Z" clip-rule="evenodd"/></svg>
                <input type="search" wire:model.live.debounce.300ms="search" class="o-input w-full ps-9 text-sm" placeholder="{{ __('Reference, customer, car or plate…') }}">
            </div>
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium text-chrome-500">{{ __('Pick-up from') }}</label>
            <x-date-field wire:model.live="from" class="o-input text-sm" />
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium text-chrome-500">{{ __('Pick-up to') }}</label>
            <x-date-field wire:model.live="to" class="o-input text-sm" />
        </div>
        @if ($from !== '' || $to !== '' || $search !== '')
            <button wire:click="$set('from', ''); $set('to', ''); $set('search', '')" class="text-sm text-chrome-500 hover:underline">{{ __('Clear') }}</button>
        @endif
    </div>

    {{-- Table --}}
    @php
        $exportQuery = http_build_query([
            'tab' => $tab, 'from' => $from, 'to' => $to, 'q' => $search,
            'ids' => $this->selectedIdsParam(),
            'title' => __('Orders'),
        ]);
    @endphp
    @if ($bulkMessage !== '')
        <div class="mb-3 flex items-start justify-between gap-3 rounded-xl bg-emerald-50 px-4 py-2 text-sm text-emerald-800 ring-1 ring-emerald-200">
            <span>{{ $bulkMessage }}</span>
            <button type="button" wire:click="$set('bulkMessage', '')" class="text-emerald-700 hover:underline">{{ __('Close') }}</button>
        </div>
    @endif
    <div class="mb-3 flex flex-wrap items-center gap-2" x-data="listExportCopy">
        <button type="button" x-on:click="copyTable('rental-orders-table')"
                class="rounded-lg border border-chrome-200 px-3 py-1.5 text-xs font-medium text-chrome-600 transition hover:bg-chrome-50">
            <span x-show="! copied">{{ __('Copy') }}</span>
            <span x-show="copied" x-cloak class="text-emerald-600">{{ __('Copied') }}</span>
        </button>
        <a href="{{ url('/app/rental/order/export/csv') }}?{{ $exportQuery }}"
           class="rounded-lg border border-chrome-200 px-3 py-1.5 text-xs font-medium text-chrome-600 transition hover:bg-chrome-50">{{ __('CSV') }}</a>
        <a href="{{ url('/app/rental/order/export/excel') }}?{{ $exportQuery }}"
           class="rounded-lg border border-chrome-200 px-3 py-1.5 text-xs font-medium text-chrome-600 transition hover:bg-chrome-50">{{ __('Excel') }}</a>
        <a href="{{ url('/app/rental/order/export/pdf') }}?{{ $exportQuery }}"
           class="rounded-lg border border-chrome-200 px-3 py-1.5 text-xs font-medium text-chrome-600 transition hover:bg-chrome-50">{{ __('PDF') }}</a>
        <a href="{{ url('/app/rental/order/export/print') }}?{{ $exportQuery }}" target="_blank" rel="noopener"
           class="rounded-lg border border-chrome-200 px-3 py-1.5 text-xs font-medium text-chrome-600 transition hover:bg-chrome-50">{{ __('Print') }}</a>
        @if (count($selected) > 0)
            <span class="ms-1 inline-flex items-center gap-2 rounded-full bg-primary-50 px-3 py-1 text-xs font-medium text-primary-700 ring-1 ring-primary-200">
                {{ __(':count selected', ['count' => count($selected)]) }}
                <button type="button" wire:click="clearSelection" class="font-semibold hover:underline">{{ __('Clear selection') }}</button>
            </span>
            @if ($canCancel)
                <button type="button" wire:click="cancelSelected"
                        wire:confirm="{{ __('Cancel the ticked orders? Their cars become available again.') }}"
                        class="rounded-lg border border-amber-200 px-3 py-1.5 text-xs font-medium text-amber-700 transition hover:bg-amber-50">{{ __('Cancel selected') }}</button>
            @endif
            @if ($canDelete)
                <button type="button" wire:click="deleteSelected"
                        wire:confirm="{{ __('Delete the ticked orders for good? Orders with an invoice or money received are kept.') }}"
                        class="rounded-lg border border-red-200 px-3 py-1.5 text-xs font-medium text-red-600 transition hover:bg-red-50">{{ __('Delete selected') }}</button>
            @endif
        @else
            <span class="ms-1 text-xs text-chrome-400">{{ __('Tick rows to export or act on only those.') }}</span>
        @endif
    </div>

    <div class="overflow-x-auto rounded-2xl bg-white shadow-sm ring-1 ring-chrome-900/[0.06]">
        <table class="w-full min-w-[760px] divide-y divide-chrome-100 text-sm" id="rental-orders-table">
            <thead class="bg-chrome-50 text-start text-xs font-semibold uppercase tracking-wide text-chrome-500">
                <tr>
                    <th class="w-10 px-4 py-2" data-copy-skip>
                        <input type="checkbox" wire:model.live="selectPage"
                               class="rounded border-chrome-300 text-primary-600 focus:ring-primary-500"
                               aria-label="{{ __('Select all on this page') }}">
                    </th>
                    <th class="px-4 py-2 text-start">{{ __('Reference') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Customer') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Car') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Pick-up') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Return') }}</th>
                    <th class="px-4 py-2 text-end">{{ __('Amount') }}</th>
                    <th class="px-4 py-2 text-end">{{ __('Total') }}</th>
                    <th class="px-4 py-2 text-end">{{ __('Received') }}</th>
                    <th class="px-4 py-2 text-end">{{ __('Balance') }}</th>
                    <th class="px-4 py-2 text-end">{{ __('Deposit') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Status') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Payment') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-chrome-50">
                @forelse ($orders as $order)
                    @php
                        $sb = [
                            'draft' => 'bg-chrome-200 text-chrome-700',
                            'active' => 'bg-sky-100 text-sky-700',
                            'closed' => 'bg-emerald-100 text-emerald-700',
                            'cancelled' => 'bg-red-100 text-red-700',
                        ][$order->state] ?? 'bg-chrome-200 text-chrome-700';
                    @endphp
                    <tr wire:key="order-{{ $order->id }}" class="cursor-pointer hover:bg-chrome-50" data-row-selected="{{ $this->isSelected($order->id) ? 1 : 0 }}"
                        onclick="window.location='{{ url('/app/rental/order/' . $order->id) }}'">
                        {{-- The whole row opens the order, so the tick must not
                             also navigate away from the list. --}}
                        <td class="px-4 py-2" data-copy-skip onclick="event.stopPropagation()">
                            <input type="checkbox" wire:model.live="selected" value="{{ $order->id }}"
                                   class="rounded border-chrome-300 text-primary-600 focus:ring-primary-500"
                                   aria-label="{{ $order->reference }}">
                        </td>
                        <td class="px-4 py-2">
                            <div class="font-medium text-chrome-800">{{ $order->reference }}</div>
                            @if ($order->createdBy)
                                <div class="text-xs text-chrome-400">{{ __('by') }} {{ $order->createdBy->name }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-chrome-700">@if ($order->customer?->flag)<span class="me-1">{{ $order->customer->flag }}</span>@endif{{ $order->customer?->name ?? '—' }}</td>
                        <td class="px-4 py-2">
                            @if ($order->vehicle)
                                @php $sub = array_filter([$order->vehicle->plate_no, $order->vehicle->color], fn (?string $p) => $p !== null && $p !== ''); @endphp
                                <div class="font-medium text-chrome-800">{{ $order->vehicle->name }}</div>
                                @if ($sub)
                                    <div class="text-xs text-chrome-500">{{ implode(' · ', $sub) }}</div>
                                @endif
                            @else
                                <span class="text-chrome-400">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-chrome-600">{{ $order->start_date?->isoFormat('DD-MMM-YYYY') ?? '—' }}</td>
                        <td class="px-4 py-2 text-chrome-600">{{ $order->end_date?->isoFormat('DD-MMM-YYYY') ?? '—' }}</td>
                        <td class="px-4 py-2 text-end text-chrome-600">{{ \App\Erp\Views\ValueFormat::money($order->subtotal) }}</td>
                        <td class="px-4 py-2 text-end font-medium text-chrome-800">{{ \App\Erp\Views\ValueFormat::money($order->total) }}</td>
                        <td class="px-4 py-2 text-end text-chrome-600">{{ \App\Erp\Views\ValueFormat::money($order->advance_amount) }}</td>
                        <td class="px-4 py-2 text-end text-chrome-600">{{ \App\Erp\Views\ValueFormat::money($order->balance) }}</td>
                        <td class="px-4 py-2 text-end text-chrome-600">{{ \App\Erp\Views\ValueFormat::money($order->deposit) }}</td>
                        <td class="px-4 py-2"><span class="rounded px-2 py-0.5 text-[11px] font-semibold uppercase {{ $sb }}">{{ $order->state === 'draft' ? __('Reservation') : __(ucfirst($order->state)) }}</span></td>
                        <td class="px-4 py-2"><span class="rounded px-2 py-0.5 text-[11px] font-semibold uppercase {{ $order->payment_status === 'paid' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' }}">{{ __(ucfirst($order->payment_status)) }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="13"class="px-4 py-10 text-center text-sm text-chrome-400">{{ __('No orders found.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $orders->links('vendor.pagination.compact') }}
    </div>
</div>
