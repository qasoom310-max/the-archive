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
                {{ __('Drag tables to arrange them, or click between rows/columns to add a divider. Changes save automatically.') }}
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
                    'cursor-move select-none ring-2 ring-primary-400' => $editing,
                    'hover:-translate-y-0.5 hover:shadow-md' => ! $editing,
                ];
            };
        @endphp

        {{-- Wrap canvas + tray in one Alpine scope so a tray table can be
             dragged onto the canvas (shared dragId). dir=ltr: the plan is a
             physical room, never mirrored under RTL. --}}
        <div x-data="{ dragId: null }" dir="ltr" wire:key="floor-canvas-{{ $floorId }}">
            <div class="overflow-x-auto rounded-2xl border border-chrome-200 bg-chrome-50 p-3">
                <div x-ref="canvas"
                    @if ($editing)
                        @dragover.prevent
                        @drop.prevent="
                            if (dragId === null) return;
                            const rect = $refs.canvas.getBoundingClientRect();
                            const cx = Math.floor(($event.clientX - rect.left) / {{ $cell }});
                            const cy = Math.floor(($event.clientY - rect.top) / {{ $cell }});
                            $wire.moveTable(dragId, cx, cy);
                            dragId = null;
                        "
                    @endif
                    class="relative mx-auto rounded-xl bg-white"
                    style="width: {{ $cols * $cell }}px; height: {{ $rows * $cell }}px;
                        @if ($editing) background-image: linear-gradient(rgb(0 0 0/.05) 1px, transparent 1px), linear-gradient(90deg, rgb(0 0 0/.05) 1px, transparent 1px); background-size: {{ $cell }}px {{ $cell }}px; @endif">

                    {{-- Divider "walls": full-span lines an admin dropped
                         between rows/columns. Always shown (sell + edit);
                         pointer-events-none so only the gutters below catch
                         clicks. --}}
                    @foreach ($vLines as $vp)
                        <div wire:key="vline-{{ $vp }}" class="pointer-events-none absolute bottom-0 top-0 z-10 w-0.5 bg-chrome-400"
                            style="left: {{ $vp * $cell - 1 }}px;"></div>
                    @endforeach
                    @foreach ($hLines as $hp)
                        <div wire:key="hline-{{ $hp }}" class="pointer-events-none absolute left-0 right-0 z-10 h-0.5 bg-chrome-400"
                            style="top: {{ $hp * $cell - 1 }}px;"></div>
                    @endforeach

                    @if ($editing)
                        {{-- Clickable gutters: a thin strip in each channel
                             between columns / rows. Sits in the gap so it never
                             overlaps a table. Click to add/remove a divider. --}}
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
                        @php $style = 'left: ' . ($card['x'] * $cell + 6) . 'px; top: ' . ($card['y'] * $cell + 6) . 'px; width: ' . ($cell - 12) . 'px; height: ' . ($cell - 12) . 'px;'; @endphp
                        @if ($editing)
                            <div wire:key="placed-{{ $card['id'] }}" draggable="true"
                                x-on:dragstart="dragId = {{ $card['id'] }}" x-on:dragend="dragId = null"
                                @class(array_merge(['absolute z-30'], $cardClasses($card, true))) style="{{ $style }}">
                                @include('pos::partials.table-card-inner', ['card' => $card])
                            </div>
                        @else
                            <a wire:key="placed-{{ $card['id'] }}"
                                href="{{ url('/app/pos/session/' . $sessionId . '/table/' . $card['id']) }}" wire:navigate
                                @class(array_merge(['absolute z-30'], $cardClasses($card, false))) style="{{ $style }}">
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
                        {{ $editing ? __('Drag these onto the plan') : __('Not placed yet') }}
                    </p>
                    <div class="flex flex-wrap gap-3">
                        @foreach ($unplaced as $card)
                            @if ($editing)
                                <div wire:key="unplaced-{{ $card['id'] }}" draggable="true"
                                    x-on:dragstart="dragId = {{ $card['id'] }}" x-on:dragend="dragId = null"
                                    @class(array_merge(['size-20'], $cardClasses($card, true)))>
                                    @include('pos::partials.table-card-inner', ['card' => $card])
                                </div>
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
