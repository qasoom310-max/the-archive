<div class="mx-auto max-w-6xl p-4 sm:p-6">
    <x-page-header :title="__('Production & store')" :subtitle="__('Mix materials into store stock, then move it to the shop.')" icon="box" accent="primary">
        <x-slot:actions>
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
                    <select wire:model.live="move_product_id" class="o-input w-full">
                        <option value="">{{ __('— Select —') }}</option>
                        @foreach ($stocked as $p)
                            <option value="{{ $p->id }}">{{ $p->name }} · {{ __('store') }} {{ $num($p->store_stock) }}</option>
                        @endforeach
                    </select>
                    @error('move_product_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div class="mt-3">
                    <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Quantity') }}</label>
                    <input type="number" step="0.001" min="0" wire:model="move_qty" class="o-input w-full">
                    @error('move_qty') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <button wire:click="moveToShop" class="o-btn-primary mt-4 w-full justify-center">{{ __('Move to shop') }}</button>
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

            <div class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-chrome-900/[0.06]">
                <div class="border-b border-chrome-100 bg-chrome-50/60 px-5 py-3"><h2 class="text-sm font-semibold text-chrome-800">{{ __('Recent productions') }}</h2></div>
                <table class="min-w-full divide-y divide-chrome-100 text-sm">
                    <thead class="bg-chrome-50 text-xs font-semibold uppercase tracking-wide text-chrome-500">
                        <tr>
                            <th class="px-4 py-2 text-start">{{ __('Reference') }}</th>
                            <th class="px-4 py-2 text-start">{{ __('Product') }}</th>
                            <th class="px-4 py-2 text-end">{{ __('Mix (ml)') }}</th>
                            <th class="px-4 py-2 text-end">{{ __('Expected') }}</th>
                            <th class="px-4 py-2 text-end">{{ __('Produced') }}</th>
                            <th class="px-4 py-2 text-end">{{ __('Shortfall') }}</th>
                            <th class="px-4 py-2 text-start">{{ __('When') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-chrome-50">
                        @forelse ($productions as $r)
                            <tr>
                                <td class="px-4 py-2 font-medium text-chrome-800">{{ $r->reference }}</td>
                                <td class="px-4 py-2 text-chrome-700">{{ $r->product?->name ?? '—' }}</td>
                                <td class="px-4 py-2 text-end tabular-nums text-chrome-600">{{ $num($r->total_mix_ml) }}</td>
                                <td class="px-4 py-2 text-end tabular-nums text-chrome-600">{{ $r->expected_units }}</td>
                                <td class="px-4 py-2 text-end tabular-nums font-medium text-chrome-800">{{ $r->produced_units }}</td>
                                <td class="px-4 py-2 text-end tabular-nums {{ $r->variance() > 0 ? 'font-semibold text-red-600' : 'text-chrome-400' }}">{{ $r->variance() > 0 ? $r->variance() : '—' }}</td>
                                <td class="px-4 py-2 text-chrome-500">{{ $r->created_at?->isoFormat('MMM D · h:mm A') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-4 py-8 text-center text-sm text-chrome-400">{{ __('No productions yet.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($transfers->isNotEmpty())
                <div class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-chrome-900/[0.06]">
                    <div class="border-b border-chrome-100 bg-chrome-50/60 px-5 py-3"><h2 class="text-sm font-semibold text-chrome-800">{{ __('Recent moves to shop') }}</h2></div>
                    <table class="min-w-full divide-y divide-chrome-100 text-sm">
                        <tbody class="divide-y divide-chrome-50">
                            @foreach ($transfers as $t)
                                <tr>
                                    <td class="px-4 py-2 text-chrome-700">{{ $t->product?->name ?? '—' }}</td>
                                    <td class="px-4 py-2 text-end tabular-nums font-medium text-chrome-800">{{ $num($t->quantity) }}</td>
                                    <td class="px-4 py-2 text-chrome-500">{{ $t->direction === 'store_to_shop' ? __('store → shop') : __('shop → store') }}</td>
                                    <td class="px-4 py-2 text-chrome-500">{{ $t->created_at?->isoFormat('MMM D · h:mm A') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</div>
