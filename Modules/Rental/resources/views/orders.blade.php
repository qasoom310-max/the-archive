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
            'due' => __('Returns due'),
            'closed' => __('Closed'),
            'unpaid' => __('Unpaid'),
            'cancelled' => __('Cancelled'),
        ];
    @endphp
    <div class="mb-4 flex flex-wrap items-center gap-1 border-b border-chrome-200">
        @foreach ($tabs as $key => $label)
            @php $n = match ($key) { 'all' => $totalCount, 'unpaid' => $unpaidCount, 'due' => $dueCount, default => (int) $counts->get($key, 0) }; @endphp
            <button wire:click="$set('tab', '{{ $key }}')"
                class="-mb-px flex items-center gap-1.5 border-b-2 px-4 py-2 text-sm font-medium {{ $tab === $key ? 'border-primary-600 text-primary-700' : 'border-transparent text-chrome-500 hover:text-chrome-800' }}">
                {{ $label }}
                <span class="rounded-full {{ in_array($key, ['unpaid', 'due'], true) && $n > 0 ? 'bg-red-100 text-red-600' : 'bg-chrome-100 text-chrome-500' }} px-1.5 text-[11px]">{{ $n }}</span>
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
    <div class="mb-3 flex flex-wrap items-center gap-2">
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
                    <th class="sticky end-0 z-10 bg-chrome-50 px-2 py-2 text-end" data-copy-skip>{{ __('Actions') }}</th>
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
                    <tr wire:key="order-{{ $order->id }}" class="group cursor-pointer hover:bg-chrome-50" data-row-selected="{{ $this->isSelected($order->id) ? 1 : 0 }}"
                        onclick="if (! event.target.closest('[data-row-actions]')) window.location='{{ url('/app/rental/order/' . $order->id) }}'">
                        {{-- The whole row opens the order, so the tick must not
                             also navigate away from the list. --}}
                        <td class="px-4 py-2" data-copy-skip onclick="event.stopPropagation()">
                            <input type="checkbox" wire:model.live="selected" value="{{ $order->id }}"
                                   class="rounded border-chrome-300 text-primary-600 focus:ring-primary-500"
                                   aria-label="{{ $order->reference }}">
                        </td>
                        <td class="px-4 py-2">
                            <div class="font-medium text-chrome-800">{{ $order->reference }}</div>
                            @if (isset($fromWebsite[$order->id]))
                                <span class="mt-0.5 inline-block rounded bg-violet-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-violet-700" data-from-website>{{ __('Website') }}</span>
                            @endif
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
                        {{-- Row actions behind one 3-dot button, the same menu as the
                             limousine bookings (rowActionsMenu: fixed panel, flips up
                             near the bottom, teleported so the sticky cell can't clip
                             it). The row opens the order on click, so it skips clicks
                             made inside [data-row-actions]. --}}
                        <td class="sticky end-0 z-10 bg-white px-2 py-2 text-end shadow-[-8px_0_8px_-8px_rgba(0,0,0,0.12)] group-hover:bg-chrome-50" data-copy-skip data-row-actions>
                            @php
                                $item = 'flex w-full items-center gap-2 px-3 py-1.5 text-start text-xs font-medium text-chrome-800 hover:bg-chrome-50';
                                $icon = 'size-4 shrink-0 text-chrome-400';
                            @endphp
                            <div x-data="rowActionsMenu" @click.outside="open = false" @keydown.escape.window="open = false" class="relative">
                                <button type="button" @click="toggle($event.currentTarget)"
                                        class="inline-flex size-7 items-center justify-center rounded-lg text-chrome-700 transition hover:bg-chrome-100"
                                        :aria-expanded="open" aria-haspopup="true"
                                        title="{{ __('Actions') }}" aria-label="{{ __('Actions') }}">
                                    <svg class="size-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                        <path d="M10 3a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3Zm0 5.5a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3Zm0 5.5a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3Z"/>
                                    </svg>
                                </button>
                                <template x-teleport="body">
                                    <div x-ref="panel" x-show="open" x-cloak x-transition.opacity.duration.100ms
                                         :style="(coords.top === null ? 'bottom:' + coords.bottom + 'px;' : 'top:' + coords.top + 'px;') + (coords.rtl ? 'right:' + coords.right + 'px' : 'left:' + coords.left + 'px')"
                                         class="fixed z-50 max-h-[70vh] w-56 overflow-y-auto overflow-x-hidden rounded-lg border border-chrome-200 bg-white py-1 shadow-pop">
                                        <a href="{{ url('/app/rental/order/' . $order->id) }}" wire:navigate @click="open = false" class="{{ $item }}">
                                            <svg class="{{ $icon }}" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/>
                                            </svg>
                                            {{ __('Open order') }}
                                        </a>
                                        @if ($canPayLink && $order->state !== 'cancelled' && $order->balance > 0.0005)
                                            <button type="button" @click="open = false" wire:click="openPaymentLink({{ $order->id }})" class="{{ $item }}" data-pay-link>
                                                <svg class="{{ $icon }}" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M12.232 4.232a2.5 2.5 0 0 1 3.536 3.536l-1.225 1.224a.75.75 0 0 0 1.061 1.06l1.224-1.224a4 4 0 0 0-5.656-5.656l-3 3a4 4 0 0 0 .225 5.865.75.75 0 0 0 .977-1.138 2.5 2.5 0 0 1-.142-3.667l3-3Z"/><path d="M11.603 7.963a.75.75 0 0 0-.977 1.138 2.5 2.5 0 0 1 .142 3.667l-3 3a2.5 2.5 0 0 1-3.536-3.536l1.225-1.224a.75.75 0 0 0-1.061-1.06l-1.224 1.224a4 4 0 1 0 5.656 5.656l3-3a4 4 0 0 0-.225-5.865Z"/></svg>
                                                {{ __('Create payment link') }}
                                            </button>
                                        @endif
                                    </div>
                                </template>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="14" class="px-4 py-10 text-center text-sm text-chrome-400">{{ __('No orders found.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $orders->links('vendor.pagination.compact') }}
    </div>

    {{-- Online payment link: choose how much, make the link, copy it to the customer. --}}
    @if ($paymentOrderId !== null)
        <div class="fixed inset-0 z-40 flex items-center justify-center bg-chrome-900/50 p-4" wire:key="pay-link-modal" data-pay-link-modal>
            <div class="w-full max-w-md rounded-2xl bg-white shadow-xl">
                <div class="flex items-center justify-between border-b border-chrome-200 px-5 py-3">
                    <h3 class="text-sm font-semibold text-chrome-900">{{ __('Payment link') }}</h3>
                    <button type="button" wire:click="closePaymentLink" class="text-chrome-400 hover:text-chrome-700" aria-label="{{ __('Close') }}">&times;</button>
                </div>

                <div class="px-5 py-4">
                    @if ($paymentLinkUrl === '')
                        @php $shares = $this->paymentShares(); @endphp
                        @if ($shares !== [])
                            <div class="mb-3 grid grid-cols-3 gap-2" data-payment-shares>
                                @foreach ($shares as $share)
                                    @php $chosen = abs((float) $paymentAmount - $share['amount']) < 0.0005; @endphp
                                    <button type="button" wire:click="usePaymentShare({{ $share['percent'] }})"
                                            class="rounded-xl px-2 py-2 text-center ring-1 transition {{ $chosen ? 'bg-primary-400 text-chrome-900 ring-primary-500' : 'bg-white text-chrome-700 ring-chrome-200 hover:bg-chrome-50' }}">
                                        <span class="block text-sm font-semibold">{{ $share['percent'] === 100 ? __('Full balance') : __('Pay :percent%', ['percent' => $share['percent']]) }}</span>
                                        <span class="block text-xs" dir="ltr">{{ \App\Erp\Views\ValueFormat::money($share['amount']) }}</span>
                                    </button>
                                @endforeach
                            </div>
                        @endif

                        <label class="text-sm font-medium text-chrome-700">{{ __('Amount to charge') }} *</label>
                        <div class="mt-1 flex items-center gap-2">
                            <input type="number" step="0.001" min="0" wire:model="paymentAmount" class="o-input w-full" dir="ltr">
                            <span class="text-sm text-chrome-500">{{ __('BHD') }}</span>
                        </div>
                        <p class="mt-1 text-[11px] text-chrome-400">{{ __('Pre-filled with the balance. Pick 25% or 50% for a deposit, or type any amount.') }}</p>
                        @error('paymentAmount') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    @else
                        <p class="mb-2 text-sm text-emerald-700">{{ __('Link created. Send it to the customer:') }}</p>
                        <div x-data="{ copied: false }" class="flex items-center gap-2">
                            <input type="text" value="{{ $paymentLinkUrl }}" readonly onclick="this.select()"
                                   class="o-input w-full bg-chrome-50 text-xs text-chrome-600" dir="ltr">
                            <button type="button"
                                    x-on:click="navigator.clipboard.writeText('{{ $paymentLinkUrl }}'); copied = true; setTimeout(() => copied = false, 1500)"
                                    class="o-btn-ghost shrink-0 text-sm">
                                <span x-show="!copied">{{ __('Copy') }}</span>
                                <span x-show="copied" x-cloak>{{ __('Copied') }}</span>
                            </button>
                        </div>
                    @endif
                </div>

                <div class="flex items-center justify-end gap-2 border-t border-chrome-200 px-5 py-3">
                    @if ($paymentLinkUrl === '')
                        <button type="button" wire:click="createPaymentLink" wire:loading.attr="disabled" class="o-btn-primary text-sm">{{ __('Create link') }}</button>
                        <button type="button" wire:click="closePaymentLink" class="o-btn-ghost text-sm">{{ __('Cancel') }}</button>
                    @else
                        <button type="button" wire:click="closePaymentLink" class="o-btn-primary text-sm">{{ __('Done') }}</button>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
