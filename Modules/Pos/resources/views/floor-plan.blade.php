<div class="mx-auto max-w-6xl p-4 sm:p-6">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold text-chrome-900">{{ __('Floor plan') }}</h1>
            <p class="text-sm text-chrome-500">{{ __('Pick a table to start or continue its order.') }}</p>
        </div>
        <div class="flex items-center gap-2">
            @if ($canEdit && $hasTables)
                <button type="button" wire:click="toggleEditing"
                    @class([
                        'inline-flex items-center gap-1.5 rounded-lg px-4 py-2 text-sm font-semibold transition',
                        'bg-primary-400 text-chrome-900' => $editing,
                        'o-btn-ghost' => ! $editing,
                    ])>
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 21 3 16.5m0 0L7.5 12M3 16.5h13.5m0-9L21 3m0 0-4.5-4.5M21 3H7.5" transform="translate(0 4)" />
                    </svg>
                    {{ $editing ? __('Done arranging') : __('Arrange tables') }}
                </button>
            @endif
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

        @if ($editing)
            <div class="mb-3 flex items-center gap-2 rounded-lg bg-amber-50 px-4 py-2 text-sm text-amber-800 ring-1 ring-amber-200">
                <svg class="size-4 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0Zm-7-4a1 1 0 1 1-2 0 1 1 0 0 1 2 0ZM9 9a1 1 0 0 0 0 2v3a1 1 0 0 0 1 1h1a1 1 0 1 0 0-2v-3a1 1 0 0 0-1-1H9Z" clip-rule="evenodd" /></svg>
                @if ($selectedId !== null)
                    {{ __('Now click a circle in an empty square to place the table. Click the table again to cancel.') }}
                @else
                    {{ __('Click a table to pick it up, then click a circle to place it. Double-click a placed table to remove it. Click between cells to add a divider.') }}
                @endif
            </div>
        @endif

        @php
            // Status colour: green = occupied, red = needs attention (order
            // sitting untouched), white = empty. Shared by canvas + tray.
            $cardClasses = function (array $card, bool $editing): array {
                return [
                    'relative flex flex-col items-center justify-center p-2 text-center shadow-sm transition',
                    'rounded-full' => $card['shape'] === 'round',
                    'rounded-xl' => $card['shape'] !== 'round',
                    'bg-emerald-500 text-white' => $card['status'] === 'occupied',
                    'bg-red-400 text-white' => $card['status'] === 'attention',
                    'bg-white text-chrome-700 ring-1 ring-chrome-200' => $card['status'] === 'empty',
                    'cursor-pointer select-none ring-2 ring-primary-400' => $editing,
                    'hover:-translate-y-0.5 hover:shadow-md' => ! $editing,
                ];
            };
            // Pickable card: stronger ring + lift when it's the one picked up.
            $pickClasses = fn (array $card): array => array_merge(
                $cardClasses($card, true),
                ['ring-4 ring-primary-500 -translate-y-0.5 shadow-lg' => $selectedId === $card['id']],
            );
        @endphp

        {{-- Click-to-place: pick a table, then click a square's circle. No drag.
             dir=ltr: a physical room is never mirrored under RTL. x-data enables
             Escape-to-cancel. --}}
        <div x-data="{}" @keydown.escape.window="$wire.clearSelection()"
            dir="ltr" wire:key="floor-canvas-{{ $floorId }}">
            <div class="overflow-auto rounded-2xl border border-chrome-200 bg-chrome-50 p-3" style="max-height: 72vh;">
                <div class="relative rounded-xl bg-white"
                    style="width: {{ $width }}px; height: {{ $height }}px;
                        @if ($editing) background-image: linear-gradient(rgb(0 0 0/.05) 1px, transparent 1px), linear-gradient(90deg, rgb(0 0 0/.05) 1px, transparent 1px); background-size: {{ $cell }}px {{ $cell }}px; @endif">

                    {{-- Divider "walls": full-span lines. Always shown (sell +
                         edit); pointer-events-none so only the gutters catch clicks. --}}
                    @foreach ($vLines as $vp)
                        <div wire:key="vline-{{ $vp }}" class="pointer-events-none absolute bottom-0 top-0 z-10 w-0.5 bg-chrome-400"
                            style="left: {{ $vp * $cell - 1 }}px;"></div>
                    @endforeach
                    @foreach ($hLines as $hp)
                        <div wire:key="hline-{{ $hp }}" class="pointer-events-none absolute left-0 right-0 z-10 h-0.5 bg-chrome-400"
                            style="top: {{ $hp * $cell - 1 }}px;"></div>
                    @endforeach

                    @if ($editing)
                        {{-- A placement circle in the centre of every empty
                             square. Click one (after picking a table) to seat
                             the table there. Brightens while a table is held. --}}
                        @for ($r = 0; $r < $rows; $r++)
                            @for ($c = 0; $c < $cols; $c++)
                                @continue(isset($occupied[$c . '-' . $r]))
                                <button type="button" wire:click="placeAt({{ $c }}, {{ $r }})" wire:key="dot-{{ $c }}-{{ $r }}"
                                    class="group absolute z-20 flex items-center justify-center"
                                    style="left: {{ $c * $cell + intdiv($cell, 2) - 16 }}px; top: {{ $r * $cell + intdiv($cell, 2) - 16 }}px; width: 32px; height: 32px;"
                                    title="{{ __('Place table here') }}">
                                    <span @class([
                                        'rounded-full transition-all',
                                        'size-3.5 bg-primary-500 ring-4 ring-primary-500/25 group-hover:size-5' => $selectedId !== null,
                                        'size-2 bg-chrome-300 group-hover:size-3 group-hover:bg-primary-400' => $selectedId === null,
                                    ])></span>
                                </button>
                            @endfor
                        @endfor

                        {{-- Clickable gutters: a thin strip on each column / row
                             boundary. Click to add/remove a divider. --}}
                        @for ($p = 1; $p < $cols; $p++)
                            <button type="button" wire:click="toggleLine('v', {{ $p }})" wire:key="vgut-{{ $p }}"
                                class="group absolute bottom-0 top-0 z-20 flex w-3 justify-center"
                                style="left: {{ $p * $cell - 6 }}px;" title="{{ __('Add / remove divider') }}">
                                <span @class([
                                    'h-full w-0.5 rounded transition',
                                    'bg-primary-500' => in_array($p, $vLines, true),
                                    'bg-transparent group-hover:bg-primary-400/60' => ! in_array($p, $vLines, true),
                                ])></span>
                            </button>
                        @endfor
                        @for ($p = 1; $p < $rows; $p++)
                            <button type="button" wire:click="toggleLine('h', {{ $p }})" wire:key="hgut-{{ $p }}"
                                class="group absolute left-0 right-0 z-20 flex h-3 items-center"
                                style="top: {{ $p * $cell - 6 }}px;" title="{{ __('Add / remove divider') }}">
                                <span @class([
                                    'h-0.5 w-full rounded transition',
                                    'bg-primary-500' => in_array($p, $hLines, true),
                                    'bg-transparent group-hover:bg-primary-400/60' => ! in_array($p, $hLines, true),
                                ])></span>
                            </button>
                        @endfor
                    @endif

                    @forelse ($placed as $card)
                        @php $base = 'left:' . $card['x'] . 'px; top:' . $card['y'] . 'px; width:' . $table . 'px; height:' . $table . 'px;'; @endphp
                        @if ($editing)
                            {{-- Single click picks the table up; double click
                                 takes it off the plan (back to the tray). The
                                 timer distinguishes the two so a dbl-click
                                 doesn't also fire select. --}}
                            <button type="button" wire:key="placed-{{ $card['id'] }}" x-data="{ t: null }"
                                @click="clearTimeout(t); t = setTimeout(() => $wire.selectTable({{ $card['id'] }}), 220)"
                                @dblclick="clearTimeout(t); $wire.unplaceTable({{ $card['id'] }})"
                                title="{{ __('Double-click to take off the plan') }}"
                                @class(array_merge(['absolute z-30'], $pickClasses($card))) style="{{ $base }}">
                                @include('pos::partials.table-card-inner', ['card' => $card])
                            </button>
                        @else
                            <a wire:key="placed-{{ $card['id'] }}"
                                href="{{ url('/app/pos/session/' . $sessionId . '/table/' . $card['id']) }}" wire:navigate
                                @class(array_merge(['absolute z-30'], $cardClasses($card, false))) style="{{ $base }}">
                                @include('pos::partials.table-card-inner', ['card' => $card])
                            </a>
                        @endif
                    @empty
                        @if (empty($unplaced))
                            <p class="absolute inset-0 flex items-center justify-center text-sm text-chrome-400">
                                {{ __('No tables on this floor.') }}
                            </p>
                        @endif
                    @endforelse
                </div>
            </div>

            @if (! empty($unplaced))
                <div class="mt-4">
                    <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-chrome-400">
                        {{ $editing ? __('Pick one, then click a circle on the plan') : __('Not placed yet') }}
                    </p>
                    <div class="flex flex-wrap gap-3">
                        @foreach ($unplaced as $card)
                            @if ($editing)
                                <button type="button" wire:key="unplaced-{{ $card['id'] }}" wire:click="selectTable({{ $card['id'] }})"
                                    @class(array_merge(['size-20'], $pickClasses($card)))>
                                    @include('pos::partials.table-card-inner', ['card' => $card])
                                </button>
                            @else
                                <a wire:key="unplaced-{{ $card['id'] }}"
                                    href="{{ url('/app/pos/session/' . $sessionId . '/table/' . $card['id']) }}" wire:navigate
                                    @class(array_merge(['size-20'], $cardClasses($card, false)))>
                                    @include('pos::partials.table-card-inner', ['card' => $card])
                                </a>
                            @endif
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    @endif
</div>
