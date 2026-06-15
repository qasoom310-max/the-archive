@php
    $chips = [
        ['' , __('All'),          $summary['total'], 'text-chrome-700',  'bg-chrome-100 text-chrome-700'],
        ['in', __('In stock'),    $summary['in'],    'text-emerald-700', 'bg-emerald-100 text-emerald-700'],
        ['low', __('Low stock'),  $summary['low'],   'text-amber-700',   'bg-amber-100 text-amber-700'],
        ['out', __('Out of stock'), $summary['out'], 'text-red-700',     'bg-red-100 text-red-700'],
    ];
    $fmt = fn ($v) => rtrim(rtrim(number_format((float) $v, 3), '0'), '.');
@endphp

<div class="mx-auto max-w-5xl p-4 sm:p-6">
    <div class="mb-4 flex items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold text-chrome-900">{{ __('Stock Report') }}</h1>
            <p class="text-sm text-chrome-500">{{ __('Product stock health across the catalogue.') }} · {{ __('Low ≤ :n', ['n' => $fmt($threshold)]) }}</p>
        </div>
        <a href="{{ url('/app/inventory') }}" wire:navigate class="o-btn-ghost shrink-0">{{ __('Back to inventory') }}</a>
    </div>

    {{-- Summary chips — also the status filter. --}}
    <div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
        @foreach ($chips as [$value, $label, $count, $tone, $badge])
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
                    <th class="px-4 py-2.5 text-end font-semibold">{{ __('Status') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-chrome-100">
                @forelse ($products as $product)
                    @php
                        $stock = (float) $product->stock_on_hand;
                        $status = $stock <= 0 ? 'out' : ($stock <= $threshold ? 'low' : 'in');
                        $statusMeta = [
                            'in' => [__('In stock'), 'bg-emerald-100 text-emerald-700'],
                            'low' => [__('Low stock'), 'bg-amber-100 text-amber-700'],
                            'out' => [__('Out of stock'), 'bg-red-100 text-red-700'],
                        ][$status];
                        $unit = $product->unit && $product->unit !== 'qty' ? ' ' . $product->unit : '';
                    @endphp
                    <tr wire:key="stock-{{ $product->id }}" class="hover:bg-chrome-50">
                        <td class="px-4 py-2.5">
                            <a href="{{ url('/app/pos/product/' . $product->id) }}" wire:navigate
                                class="font-medium text-chrome-800 hover:text-primary-700">{{ $product->name }}</a>
                        </td>
                        <td class="px-4 py-2.5 text-chrome-500">{{ $product->category_name ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-end font-semibold tabular-nums text-chrome-900">{{ $fmt($stock) }}{{ $unit }}</td>
                        <td class="px-4 py-2.5 text-end">
                            <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium {{ $statusMeta[1] }}">{{ $statusMeta[0] }}</span>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-12 text-center text-sm text-chrome-400">{{ __('No products found.') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $products->links('vendor.pagination.compact') }}</div>
</div>
