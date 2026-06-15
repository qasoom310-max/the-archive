<div class="mx-auto max-w-6xl p-4 sm:p-6">
    <div class="mb-6">
        <h1 class="text-xl font-bold text-chrome-900">Inventory Overview</h1>
        <p class="text-sm text-chrome-500">Double-entry stock operations across all warehouses.</p>
    </div>

    {{-- KPI strip --}}
    <div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
        @php
            $kpiCells = [
                ['Open moves', $kpis['openMoves'], 'text-amber-600', null],
                ['Moves done', $kpis['movesDone'], 'text-emerald-600', null],
                // "Products in stock" is a button → opens the POS product list
                // (your stock catalogue) when POS is installed.
                ['Products in stock', $kpis['productsInStock'], 'text-primary-700', $productsUrl],
                ['Internal locations', $kpis['internalLocations'], 'text-chrome-700', null],
            ];
        @endphp
        @foreach ($kpiCells as [$label, $value, $tone, $href])
            @if ($href)
                <a href="{{ $href }}" wire:navigate
                    class="group rounded-xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/5 transition hover:shadow-md hover:ring-primary-300">
                    <p class="text-2xl font-bold {{ $tone }}">{{ $value }}</p>
                    <p class="flex items-center gap-1 text-xs uppercase tracking-wide text-chrome-400">
                        {{ $label }}
                        <svg class="size-3.5 text-chrome-300 transition group-hover:text-primary-500 rtl:rotate-180" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.168 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd"/>
                        </svg>
                    </p>
                </a>
            @else
                <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/5">
                    <p class="text-2xl font-bold {{ $tone }}">{{ $value }}</p>
                    <p class="text-xs uppercase tracking-wide text-chrome-400">{{ $label }}</p>
                </div>
            @endif
        @endforeach
    </div>

    {{-- Operation-type Kanban cards --}}
    @if (count($cards) === 0)
        <p class="rounded-xl border border-dashed border-chrome-300 bg-white p-10 text-center text-sm text-chrome-400">
            No operation types configured. Run the InventorySeeder.
        </p>
    @else
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($cards as $card)
                <div wire:key="optype-{{ $card['id'] }}"
                    class="flex flex-col rounded-xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5">
                    <div class="flex items-start justify-between">
                        <div>
                            <p class="text-base font-bold text-chrome-900">{{ $card['name'] }}</p>
                            <span class="o-chip mt-1 bg-chrome-100 text-chrome-500">{{ $card['code'] }}</span>
                        </div>
                        <span class="flex size-10 items-center justify-center rounded-xl bg-primary-50 text-sm font-bold text-primary-700">
                            {{ $card['code'] }}
                        </span>
                    </div>

                    <div class="mt-4 flex items-end gap-6">
                        <div>
                            <p class="text-3xl font-bold text-chrome-900">{{ $card['toProcess'] }}</p>
                            <p class="text-xs text-chrome-400">To process</p>
                        </div>
                        <div>
                            <p class="text-3xl font-bold {{ $card['late'] > 0 ? 'text-red-600' : 'text-chrome-300' }}">
                                {{ $card['late'] }}
                            </p>
                            <p class="text-xs {{ $card['late'] > 0 ? 'text-red-500' : 'text-chrome-400' }}">Late</p>
                        </div>
                    </div>

                    <div class="mt-5 flex gap-2 border-t border-chrome-100 pt-4">
                        <a href="{{ url('/app/inventory/transfers/new?type=' . $card['id']) }}"
                            wire:navigate class="o-btn-primary">New</a>
                        <a href="{{ url('/app/inventory/transfers?type=' . $card['id']) }}"
                            wire:navigate class="o-btn-ghost">View All</a>
                    </div>
                </div>
            @endforeach
        </div>

        <p class="mt-6 text-xs text-chrome-400">
            Counts are live, computed from active stock moves. Use <strong>New</strong> to
            create a transfer and <strong>View All</strong> to validate pending ones.
        </p>
    @endif
</div>
