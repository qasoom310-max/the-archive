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

        <div class="overflow-x-auto rounded-xl border border-chrome-200 bg-white">
            <table class="w-full min-w-[720px] text-sm">
                <thead class="bg-chrome-50 text-xs uppercase tracking-wide text-chrome-500">
                    <tr>
                        <th class="px-4 py-2.5 text-start font-semibold">{{ __('Order #') }}</th>
                        <th class="px-4 py-2.5 text-start font-semibold">{{ __('Table') }}</th>
                        <th class="px-4 py-2.5 text-start font-semibold">{{ __('Type') }}</th>
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
                            <td class="px-4 py-2.5 text-chrome-600">{{ $row['table'] ?? '—' }}</td>
                            <td class="px-4 py-2.5 text-chrome-600">{{ $row['type'] }}</td>
                            <td class="px-4 py-2.5 text-center tabular-nums text-chrome-600">{{ $row['units'] }}</td>
                            <td class="px-4 py-2.5 text-chrome-600">{{ $row['payment'] }}</td>
                            <td class="px-4 py-2.5">
                                <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium {{ $badge[$row['state']->color()] ?? 'bg-chrome-100 text-chrome-700' }}">
                                    {{ __($row['state']->label()) }}
                                </span>
                            </td>
                            <td class="px-4 py-2.5 text-end font-semibold tabular-nums text-chrome-900">{{ $money($row['total']) }}</td>
                            <td class="px-4 py-2.5">
                                <div class="flex items-center justify-end gap-1">
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
                                        <a href="{{ url('/app/pos/order/' . $row['id'] . '/receipt') }}" target="_blank" rel="noopener"
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
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-4 py-12 text-center text-sm text-chrome-400">{{ __('No orders found.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">{{ $orders->links('vendor.pagination.compact') }}</div>
    @endif

    {{-- Shared split-order overlay (opened by the per-row split icon).
         FQCN form so it resolves without the module-boot alias (see terminal). --}}
    @livewire(\Modules\Pos\Livewire\SplitOrderModal::class)
</div>
