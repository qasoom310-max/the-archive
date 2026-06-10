<div class="mx-auto max-w-5xl p-4 sm:p-6">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold text-chrome-900">{{ __('Floor plan') }}</h1>
            <p class="text-sm text-chrome-500">{{ __('Pick a table to start or continue its order.') }}</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ url('/app/pos/session/' . $sessionId . '/terminal') }}" wire:navigate
                class="o-btn-ghost text-sm">{{ __('Quick sale (no table)') }}</a>
            <a href="{{ url('/app/pos/session/' . $sessionId) }}" wire:navigate
                class="o-btn-ghost text-sm">{{ __('Manage register') }}</a>
        </div>
    </div>

    @if (! $hasTables)
        <div class="rounded-xl border border-dashed border-chrome-300 bg-white p-10 text-center">
            <p class="text-sm text-chrome-600">{{ __('No tables configured yet.') }}</p>
            <p class="mt-1 text-xs text-chrome-400">{{ __('Add floors and tables under Point of Sale → POS Tables, or just sell without a table.') }}</p>
            <a href="{{ url('/app/pos/session/' . $sessionId . '/terminal') }}" wire:navigate
                class="o-btn-primary mt-4">{{ __('Sell without a table') }}</a>
        </div>
    @else
        {{-- Floor tabs --}}
        <div class="mb-5 flex flex-wrap gap-2">
            @foreach ($floors as $floor)
                <button type="button" wire:click="selectFloor({{ $floor->id }})" wire:key="floor-{{ $floor->id }}"
                    @class([
                        'rounded-lg px-5 py-2 text-sm font-semibold transition',
                        'bg-primary-400 text-chrome-900' => $floor->id === $floorId,
                        'bg-white text-chrome-600 ring-1 ring-chrome-200 hover:bg-chrome-50' => $floor->id !== $floorId,
                    ])>
                    {{ $floor->name }}
                </button>
            @endforeach
        </div>

        @if (empty($cards))
            <p class="rounded-xl border border-dashed border-chrome-300 bg-white p-10 text-center text-sm text-chrome-400">
                {{ __('No tables on this floor.') }}
            </p>
        @else
            {{-- Table cards. Status colour: green = occupied, red = needs
                 attention (order sitting untouched), grey = empty. The badge
                 shows the running order; the pill shows guests/seats. --}}
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6">
                @foreach ($cards as $card)
                    <a href="{{ url('/app/pos/session/' . $sessionId . '/table/' . $card['id']) }}" wire:navigate
                        wire:key="table-{{ $card['id'] }}"
                        @class([
                            'relative flex aspect-square flex-col items-center justify-center p-3 text-center shadow-sm transition hover:-translate-y-0.5 hover:shadow-md',
                            'rounded-full' => $card['shape'] === 'round',
                            'rounded-xl' => $card['shape'] !== 'round',
                            'bg-emerald-500 text-white' => $card['status'] === 'occupied',
                            'bg-red-400 text-white' => $card['status'] === 'attention',
                            'bg-white text-chrome-700 ring-1 ring-chrome-200' => $card['status'] === 'empty',
                        ])>
                        @if ($card['hasOrder'])
                            <span class="absolute end-2 top-2 flex size-5 items-center justify-center rounded-full bg-chrome-900 text-[10px] font-bold text-white">1</span>
                        @endif

                        <span class="text-lg font-bold leading-none">{{ $card['name'] }}</span>

                        <span @class([
                            'mt-2 rounded-md px-2 py-0.5 text-xs font-semibold tabular-nums',
                            'bg-black/15 text-white' => $card['status'] !== 'empty',
                            'bg-chrome-100 text-chrome-500' => $card['status'] === 'empty',
                        ])>{{ $card['guests'] }}/{{ $card['seats'] }}</span>
                    </a>
                @endforeach
            </div>
        @endif
    @endif
</div>
