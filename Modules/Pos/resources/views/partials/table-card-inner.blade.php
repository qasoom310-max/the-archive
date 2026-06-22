{{-- Inner content of a floor-plan table card. Expects $card with
     keys: name, seats, guests, status, hasOrder. Shared by the canvas
     (placed) and the tray (unplaced) so they can't drift. --}}
@if ($card['hasOrder'])
    <span class="absolute end-1.5 top-1.5 flex size-5 items-center justify-center rounded-full bg-chrome-900 text-[10px] font-bold text-white">1</span>
@endif

<span class="text-lg font-bold leading-none">{{ $card['name'] }}</span>

<span @class([
    'mt-1.5 rounded-md px-2 py-0.5 text-xs font-semibold tabular-nums',
    'bg-black/15 text-white' => $card['status'] !== 'empty',
    'bg-chrome-100 text-chrome-500' => $card['status'] === 'empty',
])>{{ $card['guests'] }}/{{ $card['seats'] }}</span>
