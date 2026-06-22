{{-- Inner content of a floor-plan table card. Expects $card with keys:
     name, status, hasOrder. Shared by the canvas (placed) and the tray
     (unplaced) so they can't drift. The table colour (set by the caller)
     carries the kitchen status — no party-size pill. --}}
@if ($card['hasOrder'])
    <span class="absolute end-1.5 top-1.5 flex size-5 items-center justify-center rounded-full bg-chrome-900 text-[10px] font-bold text-white">1</span>
@endif

<span class="text-lg font-bold leading-none">{{ $card['name'] }}</span>
