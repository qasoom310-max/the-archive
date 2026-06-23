@php
    $num = fn ($v) => rtrim(rtrim(number_format((float) $v, 3), '0'), '.');
@endphp

<div class="mx-auto max-w-5xl p-4 sm:p-6">
    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold text-chrome-900">{{ __('Reorder Report') }}</h1>
            <p class="text-sm text-chrome-500">
                {{ __('Purchasable items at or below their minimum stock. Hand this to the buying team.') }}
                · {{ __('Low ≤ :n', ['n' => $num($threshold)]) }}
            </p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ url('/app/purchases/reorder/export' . ($search !== '' ? '?search=' . urlencode($search) : '')) }}"
                class="inline-flex items-center gap-1 rounded-md px-3 py-1.5 text-sm font-medium text-chrome-600 ring-1 ring-chrome-300 hover:bg-chrome-50">
                {{ __('Export CSV') }}
            </a>
            @if ($canCreate)
                <a href="{{ url('/app/purchases/purchase/new') }}" wire:navigate class="o-btn-primary">
                    <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10 5a1 1 0 0 1 1 1v3h3a1 1 0 1 1 0 2h-3v3a1 1 0 1 1-2 0v-3H6a1 1 0 1 1 0-2h3V6a1 1 0 0 1 1-1Z"/></svg>
                    {{ __('New purchase') }}
                </a>
            @endif
        </div>
    </div>

    {{-- Summary chips --}}
    <div class="mb-4 flex flex-wrap gap-2">
        <span class="o-chip bg-chrome-100 text-chrome-600">{{ __('To reorder') }}: <span class="font-semibold">{{ $summary['total'] }}</span></span>
        <span class="o-chip bg-amber-50 text-amber-700">{{ __('Low stock') }}: <span class="font-semibold">{{ $summary['low'] }}</span></span>
        <span class="o-chip bg-red-50 text-red-700">{{ __('Out of stock') }}: <span class="font-semibold">{{ $summary['out'] }}</span></span>
    </div>

    <div class="mb-3">
        <input type="text" wire:model.live.debounce.300ms="search"
            placeholder="{{ __('Search by name…') }}"
            class="o-input w-full sm:max-w-xs">
    </div>

    <div class="overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-chrome-900/5">
        <table class="min-w-full divide-y divide-chrome-200 text-sm">
            <thead class="bg-chrome-50 text-xs uppercase tracking-wide text-chrome-500">
                <tr>
                    <th class="px-4 py-2.5 text-start font-semibold">{{ __('Item') }}</th>
                    <th class="px-4 py-2.5 text-start font-semibold">{{ __('Type') }}</th>
                    <th class="px-4 py-2.5 text-end font-semibold">{{ __('Current stock') }}</th>
                    <th class="px-4 py-2.5 text-end font-semibold">{{ __('Minimum') }}</th>
                    <th class="px-4 py-2.5 text-end font-semibold">{{ __('Status') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-chrome-100">
                @forelse ($rows as $row)
                    @php
                        $unit = $row->unit !== '' ? ' ' . $row->unit : '';
                        $min = $row->reorderPoint ?? $threshold;
                        [$badgeText, $badgeClass] = $row->isCondiment()
                            ? [__('Condiment'), 'bg-primary-100 text-primary-700']
                            : ($row->isIngredient()
                                ? [__('Ingredient'), 'bg-emerald-100 text-emerald-700']
                                : [__('Product'), 'bg-chrome-100 text-chrome-600']);
                        [$statusText, $statusClass] = $row->status === 'out'
                            ? [__('Out of stock'), 'bg-red-100 text-red-700']
                            : [__('Low stock'), 'bg-amber-100 text-amber-700'];
                    @endphp
                    <tr wire:key="reorder-{{ $row->type }}-{{ $row->id }}" class="hover:bg-chrome-50">
                        <td class="px-4 py-2.5">
                            <a href="{{ url($row->url()) }}" wire:navigate
                                class="font-medium text-chrome-800 hover:text-primary-700">{{ $row->name }}</a>
                        </td>
                        <td class="px-4 py-2.5">
                            <span class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-medium {{ $badgeClass }}">{{ $badgeText }}</span>
                        </td>
                        <td class="px-4 py-2.5 text-end font-semibold tabular-nums text-chrome-900">{{ $num($row->stock) }}{{ $unit }}</td>
                        <td class="px-4 py-2.5 text-end tabular-nums text-chrome-500">{{ $num($min) }}{{ $unit }}</td>
                        <td class="px-4 py-2.5 text-end">
                            <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium {{ $statusClass }}">{{ $statusText }}</span>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-12 text-center text-sm text-chrome-400">
                            {{ __('Nothing to reorder — every purchasable item is above its minimum.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
