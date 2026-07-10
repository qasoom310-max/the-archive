@php
    $money = fn ($v) => \App\Erp\Money\Currencies::format((float) $v);
    $fmt = fn ($v) => rtrim(rtrim(number_format((float) $v, 3), '0'), '.');
    $chips = [
        ['' , __('All'),          $summary['total'], 'text-chrome-700'],
        ['in', __('In stock'),    $summary['in'],    'text-emerald-700'],
        ['low', __('Low stock'),  $summary['low'],   'text-amber-700'],
        ['out', __('Out of stock'), $summary['out'], 'text-red-700'],
    ];
    // Export / print mirror the current view scope.
    $params = http_build_query([
        'filter' => $filter,
        'search' => $search,
        'inactive' => $includeInactive ? 1 : 0,
    ]);
@endphp

<div class="mx-auto max-w-6xl p-4 sm:p-6">
    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold text-chrome-900">{{ __('Stock Report') }}</h1>
            <p class="text-sm text-chrome-500">
                {{ __('Product stock health across the catalogue.') }} · {{ __('Low ≤ :n', ['n' => $fmt($threshold)]) }}
            </p>
            <p class="mt-1 text-sm font-semibold text-chrome-700">
                {{ __('Inventory value') }}: <span class="text-primary-700">{{ $money($summary['value']) }}</span>
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <label class="flex items-center gap-1.5 text-sm text-chrome-600">
                <input type="checkbox" wire:model.live="includeInactive"
                    class="size-4 rounded border-chrome-300 text-primary-500 focus:ring-primary-400">
                {{ __('Include inactive') }}
            </label>
            <a href="{{ url('/app/pos/stock-report/export?' . $params) }}" class="o-btn-ghost text-sm">
                <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
                {{ __('Export') }}
            </a>
            <a href="{{ url('/app/pos/stock-report/print?' . $params) }}" target="_blank" rel="noopener" class="o-btn-ghost text-sm">
                {{ __('Print') }}
            </a>
            <a href="{{ url('/app/inventory') }}" wire:navigate class="o-btn-ghost text-sm">{{ __('Back to inventory') }}</a>
        </div>
    </div>

    {{-- Products vs Production (raw materials) — perfumes POS only. --}}
    @if ($showScope ?? false)
        <div class="mb-4 inline-flex rounded-xl border border-chrome-200 bg-white p-1">
            @foreach (['products' => __('Products'), 'materials' => __('Production materials')] as $val => $label)
                <button type="button" wire:click="setScope('{{ $val }}')"
                    @class([
                        'rounded-lg px-4 py-1.5 text-sm font-medium transition',
                        'bg-primary-50 text-primary-700' => ($scope ?? 'products') === $val,
                        'text-chrome-500 hover:text-chrome-800' => ($scope ?? 'products') !== $val,
                    ])>{{ $label }}</button>
            @endforeach
        </div>
    @endif

    {{-- Summary chips — also the status filter. --}}
    <div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
        @foreach ($chips as [$value, $label, $count, $tone])
            <button type="button" wire:click="setFilter('{{ $value }}')"
                @class([
                    'rounded-xl border bg-white p-4 text-start transition hover:shadow-sm',
                    'border-primary-400 ring-1 ring-primary-300' => $filter === $value,
                    'border-chrome-200' => $filter !== $value,
                ])>
                <p class="text-2xl font-bold {{ $tone }}">{{ $count }}</p>
                <p class="text-xs uppercase tracking-wide text-chrome-400">{{ $label }}</p>
            </button>
        @endforeach
    </div>

    {{-- Search --}}
    <div class="mb-3">
        <div class="relative max-w-sm">
            <svg class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-chrome-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 1 0 3.4 9.83l3.38 3.38a.75.75 0 1 0 1.06-1.06l-3.38-3.38A5.5 5.5 0 0 0 9 3.5ZM5 9a4 4 0 1 1 8 0 4 4 0 0 1-8 0Z" clip-rule="evenodd" />
            </svg>
            <input type="search" wire:model.live.debounce.300ms="search"
                placeholder="{{ __('Search products') }}" class="o-input w-full ps-9 text-sm">
        </div>
    </div>

    <div class="overflow-hidden rounded-xl border border-chrome-200 bg-white">
        <table class="w-full text-sm">
            <thead class="bg-chrome-50 text-xs uppercase tracking-wide text-chrome-500">
                <tr>
                    <th class="px-4 py-2.5 text-start font-semibold">{{ __('Product') }}</th>
                    <th class="px-4 py-2.5 text-start font-semibold">{{ __('Category') }}</th>
                    <th class="px-4 py-2.5 text-end font-semibold">{{ __('On hand') }}</th>
                    <th class="px-4 py-2.5 text-end font-semibold">{{ __('Value') }}</th>
                    <th class="px-4 py-2.5 text-end font-semibold">{{ __('Status') }}</th>
                    <th class="px-4 py-2.5 text-end font-semibold">{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-chrome-100">
                @forelse ($rows as $row)
                    @php
                        $statusMeta = [
                            'in' => [__('In stock'), 'bg-emerald-100 text-emerald-700'],
                            'low' => [__('Low stock'), 'bg-amber-100 text-amber-700'],
                            'out' => [__('Out of stock'), 'bg-red-100 text-red-700'],
                        ][$row->status];
                        $unit = $row->unit !== '' ? ' ' . $row->unit : '';
                    @endphp
                    <tr wire:key="stock-{{ $row->type }}-{{ $row->id }}" class="hover:bg-chrome-50 {{ $row->active ? '' : 'opacity-60' }}">
                        <td class="px-4 py-2.5">
                            <a href="{{ url($row->url()) }}" wire:navigate
                                class="font-medium text-chrome-800 hover:text-primary-700">{{ $row->name }}</a>
                            @if ($row->isCondiment())
                                <span class="ms-1.5 inline-flex rounded-full bg-chrome-100 px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-chrome-500">{{ __('Add-on') }}</span>
                            @elseif ($row->isIngredient())
                                <span class="ms-1.5 inline-flex rounded-full bg-emerald-100 px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-emerald-700">{{ __('Ingredient') }}</span>
                            @endif
                            @unless ($row->active)<span class="ms-1 text-xs text-chrome-400">({{ __('inactive') }})</span>@endunless
                        </td>
                        <td class="px-4 py-2.5 text-chrome-500">{{ $row->category ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-end font-semibold tabular-nums text-chrome-900">
                            {{ $fmt($row->stock) }}{{ $unit }}
                            @if ($row->isProduct() && $row->storeStock !== null && $row->storeStock > 0 && \App\Erp\Business\Features::enabled(\App\Erp\Business\Feature::Production))
                                <span class="block text-[11px] font-normal text-chrome-400">{{ __('store') }} {{ $fmt($row->storeStock) }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2.5 text-end tabular-nums text-chrome-600">{{ $money($row->value) }}</td>
                        <td class="px-4 py-2.5 text-end">
                            <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium {{ $statusMeta[1] }}">{{ $statusMeta[0] }}</span>
                        </td>
                        <td class="px-4 py-2.5">
                            <div class="flex items-center justify-end gap-2">
                                <button type="button" wire:click="openAdjust({{ $row->id }}, '{{ $row->type }}')"
                                    class="text-xs font-medium text-primary-600 hover:underline">{{ __('Adjust') }}</button>
                                @if ($row->status === 'out' && $row->isProduct() && $purchasesInstalled)
                                    <a href="{{ url('/app/purchases/purchase/new?product=' . $row->id) }}" wire:navigate
                                        class="text-xs font-medium text-chrome-500 hover:underline">{{ __('Buy') }}</a>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-12 text-center text-sm text-chrome-400">{{ __('No products found.') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $rows->links('vendor.pagination.compact') }}</div>

    {{-- Inline restock modal --}}
    @if ($adjustName !== null)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-chrome-900/40 p-4">
            <div class="w-full max-w-sm rounded-2xl bg-white p-5 shadow-pop">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-base font-bold text-chrome-900">{{ __('Set stock') }}</h2>
                    <button type="button" wire:click="closeAdjust" class="text-sm text-chrome-400 hover:text-chrome-700">✕</button>
                </div>
                <p class="mb-3 truncate text-sm text-chrome-500">{{ $adjustName }}</p>
                <label class="block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('On hand') }}</label>
                <input type="number" step="any" min="0" autofocus
                    wire:model="adjustQty" wire:keydown.enter="saveAdjust"
                    class="o-input mt-1 w-full text-sm tabular-nums">
                <div class="mt-4 flex gap-2">
                    <button type="button" wire:click="closeAdjust" class="o-btn-ghost flex-1 justify-center">{{ __('Cancel') }}</button>
                    <button type="button" wire:click="saveAdjust" class="o-btn-primary flex-1 justify-center">{{ __('Save') }}</button>
                </div>
            </div>
        </div>
    @endif
</div>
