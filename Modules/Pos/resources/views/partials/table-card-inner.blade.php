{{-- Inner content of a floor-plan table card. Expects $card with keys:
     name, status, hasOrder. Shared by the canvas (placed) and the tray
     (unplaced) so they can't drift. The table colour (set by the caller)
     carries the kitchen status — no party-size pill. --}}
@if ($card['hasOrder'])
    <span class="absolute end-1 top-1 flex size-4 items-center justify-center rounded-full bg-chrome-900 text-[9px] font-bold text-white md:end-1.5 md:top-1.5 md:size-5 md:text-[10px]">1</span>
@endif

<span class="text-sm font-bold leading-none md:text-base lg:text-lg">{{ $card['name'] }}</span>
