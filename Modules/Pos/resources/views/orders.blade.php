@php
    $money = fn ($v) => \App\Erp\Money\Currencies::format((float) $v);
    $badge = [
        'amber' => 'bg-amber-100 text-amber-700',
        'emerald' => 'bg-emerald-100 text-emerald-700',
        'red' => 'bg-red-100 text-red-700',
        'sky' => 'bg-sky-100 text-sky-700',
    ];
@endphp

<div class="mx-auto max-w-7xl p-4 sm:p-6">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold text-chrome-900">{{ __('Orders List') }}</h1>
            <p class="text-sm text-chrome-500">{{ __('Every order across all register sessions.') }}</p>
        </div>

        <div class="flex shrink-0 items-center gap-2">
            <a href="{{ url('/app/pos') }}" wire:navigate class="o-btn-ghost">{{ __('Back to sessions') }}</a>

            <div x-data="{ open: false }" @click.outside="open = false" class="relative">
                <button type="button" @click="open = !open"
                    aria-label="{{ __('Order actions') }}"
                    class="flex size-9 items-center justify-center rounded-lg border border-chrome-300 bg-white text-chrome-600 hover:bg-chrome-100">
                    <svg class="size-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M12 6.75a.75.75 0 1 1 0-1.5.75.75 0 0 1 0 1.5Zm0 6a.75.75 0 1 1 0-1.5.75.75 0 0 1 0 1.5Zm0 6a.75.75 0 1 1 0-1.5.75.75 0 0 1 0 1.5Z" />
                    </svg>
                </button>

                <div x-show="open" x-cloak x-transition.opacity
                    class="absolute end-0 z-30 mt-1 w-56 origin-top-end rounded-lg border border-chrome-200 bg-white py-2 shadow-pop">
                    <p class="px-3 pb-1 text-xs font-semibold uppercase tracking-wide text-chrome-400">{{ __('View') }}</p>
                    <a href="{{ url('/app/pos') }}" wire:navigate
                        class="block px-3 py-1.5 text-sm text-chrome-700 hover:bg-chrome-100">{{ __('Sessions') }}</a>
                    <div class="my-1 border-t border-chrome-100"></div>
                    <p class="px-3 pb-1 text-xs font-semibold uppercase tracking-wide text-chrome-400">{{ __('Reporting') }}</p>
                    <a href="{{ url('/app/pos/reporting') }}" wire:navigate
                        class="block px-3 py-1.5 text-sm text-chrome-700 hover:bg-chrome-100">{{ __('Orders') }}</a>
                </div>
            </div>
        </div>
    </div>

    <div class="mb-4 inline-flex rounded-lg bg-chrome-200 p-1">
        <button wire:click="$set('tab', 'list')"
            class="o-btn {{ $tab === 'list' ? 'bg-white text-chrome-900 shadow-sm' : 'text-chrome-600' }}">{{ __('List') }}</button>
        <button wire:click="$set('tab', 'kanban')"
            class="o-btn {{ $tab === 'kanban' ? 'bg-white text-chrome-900 shadow-sm' : 'text-chrome-600' }}">{{ __('Kanban') }}</button>
    </div>

    @if ($tab === 'kanban')
        <livewire:views.kanban-view
            :model="\Modules\Pos\Models\PosOrder::class"
            model-key="pos.order"
            title="POS Orders"
            :key="'pos-orders-kanban'" />
    @else
        {{-- Search + status filter --}}
        <div class="mb-3 flex flex-wrap items-center gap-2">
            <div class="relative flex-1 min-w-[200px]">
                <svg class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-chrome-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 1 0 3.4 9.83l3.38 3.38a.75.75 0 1 0 1.06-1.06l-3.38-3.38A5.5 5.5 0 0 0 9 3.5ZM5 9a4 4 0 1 1 8 0 4 4 0 0 1-8 0Z" clip-rule="evenodd" />
                </svg>
                <input type="search" wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search by order number') }}"
                    class="o-input w-full ps-9 text-sm">
            </div>
            <select wire:model.live="status" class="o-input text-sm">
                @foreach ($statuses as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>

        {{-- Who made the sale and when are useful on every shop, so Cashier + Time
             are always shown. Table / Type are dine-in only — on a walk-in shop
             they'd read "—/Walk-in" on every single row. --}}
        <div class="overflow-x-auto rounded-xl border border-chrome-200 bg-white">
            <table class="w-full {{ $dineIn ? 'min-w-[960px]' : 'min-w-[720px]' }} text-sm">
                <thead class="bg-chrome-50 text-xs uppercase tracking-wide text-chrome-500">
                    <tr>
                        <th class="px-4 py-2.5 text-start font-semibold">{{ __('Order #') }}</th>
                        @if ($dineIn)
                            <th class="px-4 py-2.5 text-start font-semibold">{{ __('Table') }}</th>
                            <th class="px-4 py-2.5 text-start font-semibold">{{ __('Type') }}</th>
                        @endif
                        <th class="px-4 py-2.5 text-start font-semibold">{{ __('Cashier') }}</th>
                        <th class="px-4 py-2.5 text-start font-semibold">{{ __('Date & time') }}</th>
                        <th class="px-4 py-2.5 text-center font-semibold">{{ __('Items') }}</th>
                        <th class="px-4 py-2.5 text-start font-semibold">{{ __('Payment') }}</th>
                        <th class="px-4 py-2.5 text-start font-semibold">{{ __('Status') }}</th>
                        <th class="px-4 py-2.5 text-end font-semibold">{{ __('Total') }}</th>
                        <th class="px-4 py-2.5 text-end font-semibold">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-chrome-100">
                    @forelse ($rows as $row)
                        <tr wire:key="order-{{ $row['id'] }}" class="hover:bg-chrome-50">
                            <td class="px-4 py-2.5 font-semibold text-chrome-900">{{ $row['reference'] }}</td>
                            @if ($dineIn)
                                <td class="px-4 py-2.5 text-chrome-600">{{ $row['table'] ?? '—' }}</td>
                                <td class="px-4 py-2.5 text-chrome-600">{{ $row['type'] }}</td>
                            @endif
                            <td class="px-4 py-2.5 text-chrome-600">{{ $row['cashier'] }}</td>
                            <td class="px-4 py-2.5 whitespace-nowrap text-chrome-500">{{ $row['time'] }}</td>
                            <td class="px-4 py-2.5 text-center tabular-nums text-chrome-600">{{ $row['units'] }}</td>
                            <td class="px-4 py-2.5 text-chrome-600">{{ $row['payment'] }}</td>
                            <td class="px-4 py-2.5">
                                <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium {{ $badge[$row['state']->color()] ?? 'bg-chrome-100 text-chrome-700' }}">
                                    {{ __($row['state']->label()) }}
                                </span>
                            </td>
                            <td class="px-4 py-2.5 text-end font-semibold tabular-nums text-chrome-900">
                                {{ $money($row['total']) }}
                                @if ($row['delivered'])
                                    {{-- Our delivery cost on this order (not charged to the customer). --}}
                                    <span class="block text-[10px] font-medium text-sky-600">
                                        {{ __('Delivered') }}@if ($row['deliveryFee'] > 0) · −{{ $money($row['deliveryFee']) }}@endif
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-2.5">
                                <div class="flex items-center justify-end gap-1">
                                    @if (!empty($row['proof_url']))
                                        <a href="{{ $row['proof_url'] }}" target="_blank" rel="noopener"
                                            title="{{ __('View proof of payment') }}" aria-label="{{ __('View proof of payment') }}"
                                            class="flex size-8 items-center justify-center rounded-lg text-emerald-600 hover:bg-emerald-50 hover:text-emerald-700">
                                            <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                            </svg>
                                        </a>
                                    @endif
                                    @if ($row['cancellable'])
                                        <button type="button" wire:click="cancelOrder({{ $row['id'] }})"
                                            wire:confirm="{{ __('Cancel this order?') }}"
                                            title="{{ __('Cancel order') }}" aria-label="{{ __('Cancel order') }}"
                                            class="flex size-8 items-center justify-center rounded-lg text-chrome-500 hover:bg-red-50 hover:text-red-600">
                                            <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18 18 6M6 6l12 12" />
                                            </svg>
                                        </button>
                                    @endif
                                    @if ($row['printable'])
                                        {{-- With a network printer set, the icon prints straight to it
                                             instead of opening the receipt page. --}}
                                        @php($receiptPrinter ??= \Modules\Pos\Support\ReceiptPrinter::config() ?? false)
                                        <a href="{{ url('/app/pos/order/' . $row['id'] . '/receipt') }}" target="_blank" rel="noopener"
                                            @if ($receiptPrinter) x-data x-on:click.prevent="window.printReceipt($el.getAttribute('href'), @js($receiptPrinter))" @endif
                                            title="{{ __('Print receipt') }}" aria-label="{{ __('Print receipt') }}"
                                            class="flex size-8 items-center justify-center rounded-lg text-chrome-500 hover:bg-chrome-100 hover:text-chrome-800">
                                            <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0 1 10.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0 .229 2.523a1.125 1.125 0 0 1-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.32 0H6.34m11.32 0 .748-8.235A1.125 1.125 0 0 0 17.286 8.5H6.714a1.125 1.125 0 0 0-1.122 1.265L6.34 18M16 8.5V5.625A1.125 1.125 0 0 0 14.875 4.5h-5.75A1.125 1.125 0 0 0 8 5.625V8.5" />
                                            </svg>
                                        </a>
                                    @endif
                                    @if ($row['splittable'])
                                        <button type="button" wire:click="openSplit({{ $row['id'] }})"
                                            title="{{ __('Split order') }}" aria-label="{{ __('Split order') }}"
                                            class="flex size-8 items-center justify-center rounded-lg text-chrome-500 hover:bg-primary-50 hover:text-primary-700">
                                            <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7.848 8.25l1.536.887M7.848 8.25a3 3 0 1 1-5.196-3 3 3 0 0 1 5.196 3Zm1.536.887a2.165 2.165 0 0 1 1.083 1.839v5.586m0 0a3 3 0 1 1-2.166 5.586 3 3 0 0 1 2.166-5.586Zm0 0V9m6.304-.75L13.84 11.4m4.312-3.15a3 3 0 1 0-5.196-3 3 3 0 0 0 5.196 3Zm1.536.887a2.165 2.165 0 0 0-1.083 1.839v5.586m0 0a3 3 0 1 0 2.166 5.586 3 3 0 0 0-2.166-5.586Zm0 0V9" />
                                            </svg>
                                        </button>
                                    @endif
                                    {{-- Admin-only: flag this order as delivered and record what the
                                         driver cost us. Our expense — the customer's total is untouched. --}}
                                    @if ($canDeleteSales && $row['state'] !== \Modules\Pos\Enums\OrderState::Cancelled)
                                        <button type="button" wire:click="openDelivery({{ $row['id'] }})"
                                            title="{{ __('Delivery') }}" aria-label="{{ __('Delivery') }}"
                                            class="flex size-8 items-center justify-center rounded-lg {{ $row['delivered'] ? 'text-sky-600 hover:bg-sky-50' : 'text-chrome-500 hover:bg-chrome-100 hover:text-chrome-800' }}">
                                            <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.25 18.75a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 0 1-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 0 0-3.213-9.193 2.056 2.056 0 0 0-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 0 0-10.026 0 1.106 1.106 0 0 0-.987 1.106v7.635m12-6.677v6.677m0 4.5v-4.5m0 0h-12" />
                                            </svg>
                                        </button>
                                    @endif
                                    {{-- Admin-only: put this ONE order on the day it really happened
                                         (entering a paper log after the fact). Other orders are untouched. --}}
                                    @if ($canDeleteSales)
                                        <button type="button" wire:click="openDate({{ $row['id'] }})"
                                            title="{{ __('Change date') }}" aria-label="{{ __('Change date') }}"
                                            class="flex size-8 items-center justify-center rounded-lg text-chrome-500 hover:bg-primary-50 hover:text-primary-700">
                                            <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" />
                                            </svg>
                                        </button>
                                    @endif
                                    {{-- Admin-only: permanently delete an order (used to clear test
                                         sales while setting the shop up). Irreversible — hence the confirm. --}}
                                    @if ($canDeleteSales)
                                        <button type="button" wire:click="deleteOrder({{ $row['id'] }})"
                                            wire:confirm="{{ __('Permanently delete this order? This cannot be undone.') }}"
                                            title="{{ __('Delete order') }}" aria-label="{{ __('Delete order') }}"
                                            class="flex size-8 items-center justify-center rounded-lg text-chrome-500 hover:bg-red-50 hover:text-red-600">
                                            <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                            </svg>
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $dineIn ? 10 : 8 }}" class="px-4 py-12 text-center text-sm text-chrome-400">{{ __('No orders found.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">{{ $orders->links('vendor.pagination.compact') }}</div>
    @endif

    {{-- Change ONE order's date (admin). Only the picked order moves, so the
         day-by-day sales reports stay accurate. --}}
    @if ($dateOrderId !== null)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-chrome-900/40 p-4">
            <div class="w-full max-w-sm rounded-2xl bg-white p-5 shadow-pop">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-base font-bold text-chrome-900">{{ __('Order date') }}</h2>
                    <button type="button" wire:click="closeDate" class="text-sm text-chrome-400 hover:text-chrome-700">✕</button>
                </div>
                <p class="mb-3 text-xs text-chrome-400">{{ __('The day this order belongs to. Only this order moves — the others keep their dates.') }}</p>
                <x-date-field wire:model="orderDate" wire:keydown.enter="saveDate" class="o-input w-full text-sm" />
                @error('orderDate') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                <div class="mt-4 flex gap-2">
                    <button type="button" wire:click="closeDate" class="o-btn-ghost flex-1 justify-center">{{ __('Cancel') }}</button>
                    <button type="button" wire:click="saveDate" class="o-btn-primary flex-1 justify-center">{{ __('Save') }}</button>
                </div>
            </div>
        </div>
    @endif

    {{-- Mark an order as delivered + record what the driver cost us (admin).
         This is OUR expense — the customer's total and payment are untouched. --}}
    @if ($deliveryOrderId !== null)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-chrome-900/40 p-4">
            <div class="w-full max-w-sm rounded-2xl bg-white p-5 shadow-pop">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-base font-bold text-chrome-900">{{ __('Delivered order') }}</h2>
                    <button type="button" wire:click="closeDelivery" class="text-sm text-chrome-400 hover:text-chrome-700">✕</button>
                </div>
                <p class="mb-3 text-xs text-chrome-400">{{ __('What you paid the driver for this order. It is your cost — the customer’s total and payment stay exactly as they are.') }}</p>
                <label class="block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Delivery cost') }}</label>
                <input type="number" step="0.001" min="0" autofocus
                    wire:model="deliveryFee" wire:keydown.enter="saveDelivery"
                    class="o-input mt-1 w-full text-sm tabular-nums">
                @error('deliveryFee') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                <div class="mt-4 flex gap-2">
                    <button type="button" wire:click="closeDelivery" class="o-btn-ghost flex-1 justify-center">{{ __('Cancel') }}</button>
                    <button type="button" wire:click="saveDelivery" class="o-btn-primary flex-1 justify-center">{{ __('Save') }}</button>
                </div>
                <button type="button" wire:click="clearDelivery"
                    class="mt-3 block w-full text-center text-xs font-medium text-red-600 hover:underline">{{ __('Not a delivery') }}</button>
            </div>
        </div>
    @endif

    {{-- Shared split-order overlay (opened by the per-row split icon).
         FQCN form so it resolves without the module-boot alias (see terminal). --}}
    @livewire(\Modules\Pos\Livewire\SplitOrderModal::class)
</div>
