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
                    'cursor-grab touch-none select-none ring-2 ring-primary-400 active:cursor-grabbing' => $editing,
                    'hover:-translate-y-0.5 hover:shadow-md' => ! $editing,
                ];
            };
        @endphp

        {{-- One Alpine scope drives a smooth, free-position drag for BOTH the
             placed tables and the tray (so a tray table can be dragged straight
             onto the plan). Pointer events follow the cursor live with no server
             round-trip; the position is snapped + saved only on release. dir=ltr:
             a physical room is never mirrored under RTL. --}}
        <div
            x-data="{
                id: null, fromTray: false, dragging: false, moved: false,
                sx: 0, sy: 0, dx: 0, dy: 0, gx: 0, gy: 0, grabX: 0, grabY: 0, label: '',
                snap: {{ $snap }},
                begin(e, id, fromTray, label, el) {
                    if (e.button) return;
                    this.id = id; this.fromTray = fromTray; this.label = label;
                    this.dragging = true; this.moved = false;
                    this.sx = e.clientX; this.sy = e.clientY; this.dx = 0; this.dy = 0;
                    const r = el.getBoundingClientRect();
                    this.grabX = e.clientX - r.left; this.grabY = e.clientY - r.top;
                    this.gx = r.left; this.gy = r.top;
                    try { el.setPointerCapture(e.pointerId); } catch (_) {}
                },
                moveTo(e) {
                    if (!this.dragging) return;
                    this.dx = e.clientX - this.sx; this.dy = e.clientY - this.sy;
                    this.gx = e.clientX - this.grabX; this.gy = e.clientY - this.grabY;
                    if (Math.abs(this.dx) > 3 || Math.abs(this.dy) > 3) this.moved = true;
                },
                finish(e) {
                    if (!this.dragging) return;
                    const id = this.id; this.dragging = false;
                    const c = this.$refs.canvas.getBoundingClientRect();
                    const inside = e.clientX >= c.left && e.clientX <= c.right && e.clientY >= c.top && e.clientY <= c.bottom;
                    if (id !== null && this.moved && inside) {
                        let x = Math.round((e.clientX - c.left - this.grabX) / this.snap) * this.snap;
                        let y = Math.round((e.clientY - c.top - this.grabY) / this.snap) * this.snap;
                        this.$wire.moveTable(id, Math.max(0, x), Math.max(0, y));
                    }
                    this.id = null; this.fromTray = false; this.moved = false; this.dx = 0; this.dy = 0;
                }
            }"
            @pointermove.window="moveTo($event)"
            @pointerup.window="finish($event)"
            dir="ltr" wire:key="floor-canvas-{{ $floorId }}"
        >
            <div class="overflow-auto rounded-2xl border border-chrome-200 bg-chrome-50 p-3" style="max-height: 72vh;">
                <div x-ref="canvas" class="relative rounded-xl bg-white"
                    style="width: {{ $width }}px; height: {{ $height }}px;
                        @if ($editing) background-image: linear-gradient(rgb(0 0 0/.04) 1px, transparent 1px), linear-gradient(90deg, rgb(0 0 0/.04) 1px, transparent 1px); background-size: {{ $cell }}px {{ $cell }}px; @endif">

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
                            <div wire:key="placed-{{ $card['id'] }}"
                                @pointerdown.prevent="begin($event, {{ $card['id'] }}, false, @js((string) $card['name']), $el)"
                                @class(array_merge(['absolute z-30'], $cardClasses($card, true)))
                                :style="'{{ $base }}' + (id === {{ $card['id'] }} && !fromTray ? ' transform: translate(' + dx + 'px,' + dy + 'px); z-index:50; box-shadow:0 12px 28px rgba(0,0,0,.22);' : '')">
                                @include('pos::partials.table-card-inner', ['card' => $card])
                            </div>
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
                        {{ $editing ? __('Drag these onto the plan') : __('Not placed yet') }}
                    </p>
                    <div class="flex flex-wrap gap-3">
                        @foreach ($unplaced as $card)
                            @if ($editing)
                                <div wire:key="unplaced-{{ $card['id'] }}"
                                    @pointerdown.prevent="begin($event, {{ $card['id'] }}, true, @js((string) $card['name']), $el)"
                                    @class(array_merge(['size-20'], $cardClasses($card, true)))
                                    :class="id === {{ $card['id'] }} && fromTray ? 'opacity-30' : ''">
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

            {{-- Floating preview that follows the cursor while dragging a tray
                 table onto the plan (placed tables move in-place instead). --}}
            <div x-show="dragging && fromTray" x-cloak
                class="pointer-events-none fixed z-[60] flex items-center justify-center rounded-xl bg-emerald-500 text-lg font-bold text-white shadow-2xl ring-2 ring-primary-400"
                :style="`left:${gx}px; top:${gy}px; width:{{ $table }}px; height:{{ $table }}px;`"
                x-text="label"></div>
        </div>
    @endif
</div>
