<div class="mx-auto max-w-6xl p-4 sm:p-6">
    <x-page-header :title="__('Production & store')" :subtitle="__('Mix materials into store stock, then move it to the shop.')" icon="box" accent="primary">
        <x-slot:actions>
            @if ($isAdmin)
                <button type="button" wire:click="recomputeCosts"
                    wire:confirm="{{ __('Recompute every perfume and offer cost from current material prices?') }}"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-chrome-200 bg-white px-3 py-2 text-sm font-medium text-chrome-700 hover:bg-chrome-50">
                    <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4 2a1 1 0 0 1 1 1v2.101a7.002 7.002 0 0 1 11.601 2.566 1 1 0 1 1-1.885.666A5.002 5.002 0 0 0 5.999 7H9a1 1 0 0 1 0 2H4a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1Zm.008 9.057a1 1 0 0 1 1.276.61A5.002 5.002 0 0 0 14.001 13H11a1 1 0 1 1 0-2h5a1 1 0 0 1 1 1v5a1 1 0 1 1-2 0v-2.101a7.002 7.002 0 0 1-11.601-2.566 1 1 0 0 1 .61-1.276Z" clip-rule="evenodd"/></svg>
                    {{ __('Recompute costs') }}
                </button>
            @endif
            <a href="{{ url('/app/pos/production/new') }}" wire:navigate class="o-btn-primary">
                <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10 5a1 1 0 0 1 1 1v3h3a1 1 0 1 1 0 2h-3v3a1 1 0 1 1-2 0v-3H6a1 1 0 1 1 0-2h3V6a1 1 0 0 1 1-1Z"/></svg>
                {{ __('New production') }}
            </a>
        </x-slot:actions>
    </x-page-header>

    @if (session('toast'))
        <div class="mb-4 rounded-lg bg-emerald-50 px-4 py-2.5 text-sm font-medium text-emerald-700 ring-1 ring-emerald-100">{{ session('toast') }}</div>
    @endif

    @php $num = fn ($v) => rtrim(rtrim(number_format((float) $v, 3), '0'), '.'); @endphp

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        {{-- Move store → shop --}}
        <div class="lg:col-span-1">
            <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/[0.06] lg:sticky lg:top-6">
                <h2 class="mb-1 text-sm font-semibold text-chrome-800">{{ __('Move to shop') }}</h2>
                <p class="mb-4 text-xs text-chrome-400">{{ __('Release finished bottles from the store to the shop so the register can sell them.') }}</p>
                <div>
                    <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Product') }}</label>
                    <x-searchable-select wire:model.live="move_product_id" class="o-input w-full"
                        :options="collect($stocked)->map(fn ($p) => ['value' => $p->id, 'label' => $p->name . ' · ' . __('store') . ' ' . $num($p->store_stock)])->all()"
                        :search-placeholder="__('Search product…')" />
                    @error('move_product_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                @if ($move_product_id && $productionOptions->isNotEmpty())
                    <div class="mt-3">
                        <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('From production') }} <span class="font-normal text-chrome-400">({{ __('optional') }})</span></label>
                        <select wire:model="move_production_id" class="o-input w-full">
                            <option value="">{{ __('— Not from a specific run —') }}</option>
                            @foreach ($productionOptions as $run)
                                <option value="{{ $run->id }}">{{ $run->reference }} · {{ $run->produced_units }} {{ __('bottles') }} · {{ $run->created_at?->isoFormat('DD-MMM') }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-chrome-400">{{ __('Tags this move with the run it came from — the date + ID show in the history.') }}</p>
                    </div>
                @endif
                <div class="mt-3">
                    <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Quantity') }}</label>
                    <input type="number" step="0.001" min="0" wire:model="move_qty" class="o-input w-full">
                    @error('move_qty') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <button wire:click="moveToShop" class="o-btn-primary mt-4 w-full justify-center">{{ __('Move to shop') }}</button>

                <div class="mt-5 border-t border-chrome-100 pt-4">
                    <h3 class="mb-1 text-sm font-semibold text-chrome-800">{{ __('Move back to store') }}</h3>
                    <p class="mb-3 text-xs text-chrome-400">{{ __('Pull bottles off the register back into the store — e.g. to return a run’s bottles so it can be reopened or reversed.') }}</p>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Product') }}</label>
                        <x-searchable-select wire:model="back_product_id" class="o-input w-full"
                            :options="collect($stocked)->map(fn ($p) => ['value' => $p->id, 'label' => $p->name . ' · ' . __('shop') . ' ' . $num($p->stock_on_hand)])->all()"
                            :search-placeholder="__('Search product…')" />
                        @error('back_product_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="mt-3">
                        <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Quantity') }}</label>
                        <input type="number" step="0.001" min="0" wire:model="back_qty" class="o-input w-full">
                        @error('back_qty') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <button wire:click="moveToStore"
                        class="mt-4 w-full justify-center rounded-lg border border-chrome-200 bg-white px-4 py-2 text-sm font-semibold text-chrome-700 hover:bg-chrome-50">
                        {{ __('Move back to store') }}
                    </button>
                </div>

                @if ($isAdmin)
                    <div class="mt-5 border-t border-chrome-100 pt-4">
                        <h3 class="mb-1 text-sm font-semibold text-chrome-800">{{ __('Remove from store') }}</h3>
                        <p class="mb-3 text-xs text-chrome-400">{{ __('Take bottles entered by mistake out of the store. Materials are not returned — to also put them back, delete the production run instead.') }}</p>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Product') }}</label>
                            <x-searchable-select wire:model="remove_product_id" class="o-input w-full"
                                :options="collect($stocked)->map(fn ($p) => ['value' => $p->id, 'label' => $p->name . ' · ' . __('store') . ' ' . $num($p->store_stock)])->all()"
                                :search-placeholder="__('Search product…')" />
                            @error('remove_product_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div class="mt-3">
                            <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Quantity') }}</label>
                            <input type="number" step="0.001" min="0" wire:model="remove_qty" class="o-input w-full">
                            @error('remove_qty') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <button wire:click="removeFromStore" wire:confirm="{{ __('Remove these bottles from the store? This cannot be undone.') }}"
                            class="mt-4 w-full justify-center rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-100">
                            {{ __('Remove from store') }}
                        </button>
                    </div>
                @endif
            </div>
        </div>

        {{-- Store vs shop overview + history --}}
        <div class="space-y-6 lg:col-span-2">
            <div class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-chrome-900/[0.06]">
                <div class="border-b border-chrome-100 bg-chrome-50/60 px-5 py-3"><h2 class="text-sm font-semibold text-chrome-800">{{ __('Stock by location') }}</h2></div>
                <table class="min-w-full divide-y divide-chrome-100 text-sm">
                    <thead class="bg-chrome-50 text-xs font-semibold uppercase tracking-wide text-chrome-500">
                        <tr><th class="px-4 py-2 text-start">{{ __('Product') }}</th><th class="px-4 py-2 text-end">{{ __('Store') }}</th><th class="px-4 py-2 text-end">{{ __('Shop') }}</th></tr>
                    </thead>
                    <tbody class="divide-y divide-chrome-50">
                        @forelse ($stocked as $p)
                            <tr>
                                <td class="px-4 py-2 font-medium text-chrome-800">{{ $p->name }}</td>
                                <td class="px-4 py-2 text-end tabular-nums text-chrome-700">{{ $num($p->store_stock) }}</td>
                                <td class="px-4 py-2 text-end tabular-nums font-medium text-chrome-800">{{ $num($p->stock_on_hand) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="px-4 py-8 text-center text-sm text-chrome-400">{{ __('No production stock yet.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="overflow-x-auto rounded-2xl bg-white shadow-sm ring-1 ring-chrome-900/[0.06]">
                <div class="border-b border-chrome-100 bg-chrome-50/60 px-5 py-3"><h2 class="text-sm font-semibold text-chrome-800">{{ __('Recent productions') }}</h2></div>
                <table class="w-full min-w-[640px] divide-y divide-chrome-100 text-sm">
                    <thead class="bg-chrome-50 text-xs font-semibold uppercase tracking-wide text-chrome-500">
                        <tr>
                            <th class="px-4 py-2 text-start">{{ __('Reference') }}</th>
                            <th class="px-4 py-2 text-start">{{ __('Product') }}</th>
                            <th class="px-4 py-2 text-end">{{ __('Mix (ml)') }}</th>
                            <th class="px-4 py-2 text-end">{{ __('Expected') }}</th>
                            <th class="px-4 py-2 text-end">{{ __('Produced') }}</th>
                            <th class="px-4 py-2 text-end">{{ __('Shortfall') }}</th>
                            <th class="px-4 py-2 text-start">{{ __('When') }}</th>
                            <th class="px-4 py-2 text-end">{{ __('Edit') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-chrome-50">
                        @forelse ($productions as $r)
                            <tr class="cursor-pointer hover:bg-chrome-50" onclick="window.location='{{ url('/app/pos/production/' . $r->id) }}'">
                                <td class="px-4 py-2 font-medium text-primary-700">
                                    {{ $r->reference }}
                                    @if ($r->state === 'reversed')
                                        <span class="ms-1 inline-flex items-center rounded bg-red-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-red-700">{{ __('Reversed') }}</span>
                                    @elseif ($r->state === 'draft')
                                        <span class="ms-1 inline-flex items-center rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-700">{{ __('Draft') }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-chrome-700">{{ $r->product?->name ?? '—' }}</td>
                                <td class="px-4 py-2 text-end tabular-nums text-chrome-600">{{ $num($r->total_mix_ml) }}</td>
                                <td class="px-4 py-2 text-end tabular-nums text-chrome-600">{{ $r->expected_units }}</td>
                                <td class="px-4 py-2 text-end tabular-nums font-medium text-chrome-800">{{ $r->produced_units }}</td>
                                <td class="px-4 py-2 text-end tabular-nums {{ $r->variance() > 0 ? 'font-semibold text-red-600' : 'text-chrome-400' }}">{{ $r->variance() > 0 ? $r->variance() : '—' }}</td>
                                <td class="px-4 py-2 text-chrome-500">{{ $r->created_at?->isoFormat('DD-MMM · h:mm A') }}</td>
                                <td class="px-4 py-2 text-end" onclick="event.stopPropagation()">
                                    <a href="{{ url('/app/pos/production/' . $r->id) }}" wire:navigate
                                        class="inline-flex items-center gap-1 rounded-lg bg-chrome-100 px-2.5 py-1 text-xs font-medium text-chrome-700 hover:bg-primary-400 hover:text-chrome-900">
                                        <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor"><path d="M13.586 3.586a2 2 0 1 1 2.828 2.828l-8.5 8.5A1 1 0 0 1 7.5 15H5a1 1 0 0 1-1-1v-2.5a1 1 0 0 1 .293-.707l8.5-8.5Z"/></svg>
                                        {{ __('Edit') }}
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="px-4 py-8 text-center text-sm text-chrome-400">{{ __('No productions yet.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($transfers->isNotEmpty())
                <div class="overflow-x-auto rounded-2xl bg-white shadow-sm ring-1 ring-chrome-900/[0.06]">
                    <div class="border-b border-chrome-100 bg-chrome-50/60 px-5 py-3"><h2 class="text-sm font-semibold text-chrome-800">{{ __('Recent stock moves') }}</h2></div>
                    <table class="min-w-full divide-y divide-chrome-100 text-sm">
                        <tbody class="divide-y divide-chrome-50">
                            @foreach ($transfers as $t)
                                @php
                                    $dir = match ($t->direction) {
                                        'store_to_shop' => __('store → shop'),
                                        'shop_to_store' => __('shop → store'),
                                        'store_remove' => __('removed from store'),
                                        default => $t->direction,
                                    };
                                @endphp
                                <tr>
                                    <td class="px-4 py-2 text-chrome-700">{{ $t->product?->name ?? '—' }}</td>
                                    <td class="px-4 py-2 text-end tabular-nums font-medium text-chrome-800">{{ $num($t->quantity) }}</td>
                                    <td class="px-4 py-2 {{ $t->direction === 'store_remove' ? 'text-red-600' : 'text-chrome-500' }}">{{ $dir }}</td>
                                    <td class="px-4 py-2 text-chrome-500">
                                        @if ($t->production)
                                            <span class="font-medium text-primary-700">{{ $t->production->reference }}</span>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="px-4 py-2 text-chrome-500">{{ $t->created_at?->isoFormat('DD-MMM · h:mm A') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</div>
