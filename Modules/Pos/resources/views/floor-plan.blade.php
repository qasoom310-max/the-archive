{{-- Poll for live colour changes as the kitchen advances tickets (red →
     yellow → green). Paused while arranging so a re-render can't disrupt a
     pick-up / placement in progress. --}}
<div class="mx-auto max-w-6xl p-4 sm:p-6" {{ $editing ? '' : 'wire:poll.15s' }}>
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
                class="o-btn-ghost text-sm">{{ __('Dine-out') }}</a>
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
                        'bg-primary-400 text-chrome-900' => ! $showUnpaid && $floor->id === $floorId,
                        'bg-white text-chrome-600 ring-1 ring-chrome-200 hover:bg-chrome-50' => $showUnpaid || $floor->id !== $floorId,
                    ])>
                    {{ $floor->name }}
                </button>
            @endforeach
            {{-- The named pay-later orders. --}}
            <button type="button" wire:click="showUnpaidOrders"
                @class([
                    'inline-flex items-center gap-2 rounded-lg px-5 py-2 text-sm font-semibold transition',
                    'bg-primary-400 text-chrome-900' => $showUnpaid,
                    'bg-white text-chrome-600 ring-1 ring-chrome-200 hover:bg-chrome-50' => ! $showUnpaid,
                ])>
                {{ __('Pay-later orders') }}
                @if (count($unpaid) > 0)
                    <span class="rounded-full bg-red-500 px-2 py-0.5 text-xs font-bold text-white">{{ count($unpaid) }}</span>
                @endif
            </button>
        </div>

        @if ($showUnpaid)
            @php
                $dot = [
                    'pending' => 'bg-red-500',
                    'preparing' => 'bg-amber-400',
                    'ready' => 'bg-emerald-500',
                ];
            @endphp

            {{-- A pay-later order known by a name, not a table on the floor. --}}
            @if ($canCreateOrder)
                <form wire:submit="openNamedOrder" class="mb-4 rounded-xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/5">
                    <label for="pay-later-name" class="mb-1 block text-sm font-semibold text-chrome-800">{{ __('New pay-later order') }}</label>
                    <p class="mb-3 text-xs text-chrome-500">{{ __('Type a name for the order (a customer or a place, e.g. Abu Ali or Outside bench). It stays here under that name until it is paid.') }}</p>
                    <div class="flex flex-wrap gap-2">
                        <input id="pay-later-name" type="text" wire:model="newOrderName" maxlength="80" autocomplete="off"
                            placeholder="{{ __('Name') }}" class="o-input min-w-0 flex-1">
                        <button type="submit" class="o-btn-primary" wire:loading.attr="disabled" wire:target="openNamedOrder">
                            {{ __('Start order') }}
                        </button>
                    </div>
                    @error('newOrderName')<p class="mt-1 text-xs font-medium text-red-600">{{ $message }}</p>@enderror
                </form>
            @endif

            @if ($unpaid === [])
                <div class="rounded-xl border border-dashed border-chrome-300 bg-white p-10 text-center text-sm text-chrome-500">
                    {{ __('No pay-later orders right now.') }}
                </div>
            @else
                <div class="divide-y divide-chrome-100 overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-chrome-900/5">
                    @foreach ($unpaid as $u)
                        <div wire:key="unpaid-{{ $u['id'] }}" class="flex items-center transition hover:bg-chrome-50">
                            <a href="{{ $u['url'] }}" wire:navigate class="flex min-w-0 flex-1 items-center gap-4 px-4 py-3">
                                <span class="size-3 shrink-0 rounded-full {{ $u['items'] > 0 ? ($dot[$u['status']] ?? 'bg-emerald-500') : 'bg-chrome-300' }}"></span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-base font-semibold text-chrome-900">
                                        {{ $u['table'] ?? __('Without a table') }}
                                        @if ($u['named'])
                                            <span class="font-normal text-chrome-500">· {{ __('Pay-later order') }}</span>
                                        @elseif ($u['floor'] !== null)
                                            <span class="font-normal text-chrome-500">· {{ $u['floor'] }}</span>
                                        @endif
                                    </span>
                                    <span class="block text-xs text-chrome-500">
                                        {{ $u['reference'] }}
                                        · {{ __('Items: :count', ['count' => rtrim(rtrim(number_format($u['items'], 3), '0'), '.')]) }}
                                        @if ($u['since'] !== null)
                                            · {{ __('open :time', ['time' => $u['since']->diffForHumans(null, true)]) }}
                                        @endif
                                    </span>
                                </span>
                                <span class="shrink-0 text-end text-base font-bold text-chrome-900">{{ \App\Erp\Money\Currencies::format($u['total']) }}</span>
                                <svg class="size-4 shrink-0 text-chrome-400 rtl:rotate-180" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m9 5 7 7-7 7" /></svg>
                            </a>
                            {{-- A named order nothing was added to can just go. --}}
                            @if ($u['named'] && $u['items'] <= 0)
                                <button type="button" wire:click="discardNamedOrder({{ $u['id'] }})"
                                    wire:confirm="{{ __('Remove this empty order?') }}"
                                    class="me-3 rounded-lg p-2 text-chrome-400 transition hover:bg-red-50 hover:text-red-600"
                                    title="{{ __('Remove') }}" aria-label="{{ __('Remove') }}">
                                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                                </button>
                            @endif
                        </div>
                    @endforeach
                    <div class="flex items-center justify-between bg-chrome-50 px-4 py-3 text-sm">
                        <span class="font-medium text-chrome-600">{{ __('Total waiting to be paid') }}</span>
                        <span class="font-bold text-chrome-900">{{ \App\Erp\Money\Currencies::format(array_sum(array_column($unpaid, 'total'))) }}</span>
                    </div>
                </div>
            @endif
        @else
        {{-- Colour legend: what each table colour means. --}}
        @unless ($editing)
            <div class="mb-4 flex flex-wrap items-center gap-x-5 gap-y-2 text-xs text-chrome-500">
                <span class="inline-flex items-center gap-1.5"><span class="size-3 rounded-full bg-red-500"></span>{{ __('Sent — not started') }}</span>
                <span class="inline-flex items-center gap-1.5"><span class="size-3 rounded-full bg-amber-400"></span>{{ __('Preparing') }}</span>
                <span class="inline-flex items-center gap-1.5"><span class="size-3 rounded-full bg-emerald-500"></span>{{ __('Ready — awaiting payment') }}</span>
                <span class="inline-flex items-center gap-1.5"><span class="size-3 rounded-full bg-white ring-1 ring-chrome-300"></span>{{ __('Available') }}</span>
            </div>
        @endunless

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
            // Status colour by KITCHEN state: red = sent (cook not started),
            // yellow = preparing, green = ready / no kitchen work (awaiting
            // payment), white = free. Shared by canvas + tray.
            $cardClasses = function (array $card, bool $editing): array {
                return [
                    'relative flex flex-col items-center justify-center p-2 text-center shadow-sm transition',
                    'rounded-full' => $card['shape'] === 'round',
                    'rounded-xl' => $card['shape'] !== 'round',
                    'bg-red-500 text-white' => $card['status'] === 'pending',
                    'bg-amber-400 text-chrome-900' => $card['status'] === 'preparing',
                    'bg-emerald-500 text-white' => $card['status'] === 'ready',
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

        {{-- Click-to-place on a REAL CSS grid of cells: every table IS a grid
             cell, so it can never escape the canvas or land between cells. Pick
             a table, then click a square's circle. dir=ltr: a physical room is
             never mirrored under RTL. x-data enables Escape-to-cancel. --}}
        {{-- Cell / table pixel size is a CSS var set per breakpoint, so the
             whole plan scales DOWN on phone + tablet (smaller cells, tables and
             divider spacing) while the grid coordinates stored server-side stay
             in the fixed 96px space — display size is purely cosmetic. --}}
        <div x-data="{}" @keydown.escape.window="$wire.clearSelection()"
            dir="ltr" wire:key="floor-canvas-{{ $floorId }}"
            class="[--cell:58px] [--tbl:50px] md:[--cell:74px] md:[--tbl:64px] lg:[--cell:96px] lg:[--tbl:84px]">
            <div class="overflow-auto rounded-2xl border border-chrome-200 bg-chrome-50 p-3" style="max-height: 72vh;">
                <div class="relative rounded-xl bg-white" style="width: calc(var(--cell) * {{ $cols }}); height: calc(var(--cell) * {{ $rows }});">

                    {{-- The grid: COLS×ROWS fixed cells, row-major. Each cell is
                         either a table (occupied) or a placement circle (empty,
                         edit mode). Tables physically live in the grid — they
                         cannot overflow or misalign. --}}
                    <div class="grid" style="grid-template-columns: repeat({{ $cols }}, var(--cell)); grid-auto-rows: var(--cell);">
                        @for ($r = 0; $r < $rows; $r++)
                            @for ($c = 0; $c < $cols; $c++)
                                @php $card = $cells[$c . '-' . $r] ?? null; @endphp
                                <div wire:key="cell-{{ $c }}-{{ $r }}"
                                    @class(['flex items-center justify-center', 'ring-1 ring-chrome-100' => $editing])>
                                    @if ($card)
                                        @if ($editing)
                                            {{-- Single click picks up; double-click removes (back to tray).
                                                 A 220ms timer keeps the two from colliding. --}}
                                            <button type="button" wire:key="placed-{{ $card['id'] }}" x-data="{ t: null }"
                                                @click="clearTimeout(t); t = setTimeout(() => $wire.selectTable({{ $card['id'] }}), 220)"
                                                @dblclick="clearTimeout(t); $wire.unplaceTable({{ $card['id'] }})"
                                                title="{{ __('Double-click to take off the plan') }}"
                                                style="width: var(--tbl); height: var(--tbl);"
                                                @class($pickClasses($card))>
                                                @include('pos::partials.table-card-inner', ['card' => $card])
                                            </button>
                                        @else
                                            <a wire:key="placed-{{ $card['id'] }}"
                                                href="{{ url('/app/pos/session/' . $sessionId . '/table/' . $card['id']) }}" wire:navigate
                                                style="width: var(--tbl); height: var(--tbl);"
                                                @class($cardClasses($card, false))>
                                                @include('pos::partials.table-card-inner', ['card' => $card])
                                            </a>
                                        @endif
                                    @elseif ($editing)
                                        <button type="button" wire:click="placeAt({{ $c }}, {{ $r }})"
                                            class="group flex size-8 items-center justify-center" title="{{ __('Place table here') }}">
                                            <span @class([
                                                'rounded-full transition-all',
                                                'size-3.5 bg-primary-500 ring-4 ring-primary-500/25 group-hover:size-5' => $selectedId !== null,
                                                'size-2 bg-chrome-300 group-hover:size-3 group-hover:bg-primary-400' => $selectedId === null,
                                            ])></span>
                                        </button>
                                    @endif
                                </div>
                            @endfor
                        @endfor
                    </div>

                    {{-- Divider "walls" overlay the grid (absolute on the relative
                         container). Solid lines are pointer-events-none; only the
                         gutter strips (edit mode) catch clicks. --}}
                    @foreach ($vLines as $vp)
                        <div wire:key="vline-{{ $vp }}" class="pointer-events-none absolute bottom-0 top-0 z-10 w-0.5 bg-chrome-400"
                            style="left: calc(var(--cell) * {{ $vp }} - 1px);"></div>
                    @endforeach
                    @foreach ($hLines as $hp)
                        <div wire:key="hline-{{ $hp }}" class="pointer-events-none absolute left-0 right-0 z-10 h-0.5 bg-chrome-400"
                            style="top: calc(var(--cell) * {{ $hp }} - 1px);"></div>
                    @endforeach

                    @if ($editing)
                        @for ($p = 1; $p < $cols; $p++)
                            <button type="button" wire:click="toggleLine('v', {{ $p }})" wire:key="vgut-{{ $p }}"
                                class="group absolute bottom-0 top-0 z-20 flex w-3 justify-center"
                                style="left: calc(var(--cell) * {{ $p }} - 6px);" title="{{ __('Add / remove divider') }}">
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
                                style="top: calc(var(--cell) * {{ $p }} - 6px);" title="{{ __('Add / remove divider') }}">
                                <span @class([
                                    'h-0.5 w-full rounded transition',
                                    'bg-primary-500' => in_array($p, $hLines, true),
                                    'bg-transparent group-hover:bg-primary-400/60' => ! in_array($p, $hLines, true),
                                ])></span>
                            </button>
                        @endfor
                    @endif

                    @if (empty($cells) && empty($unplaced))
                        <p class="pointer-events-none absolute inset-0 flex items-center justify-center text-sm text-chrome-400">
                            {{ __('No tables on this floor.') }}
                        </p>
                    @endif
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
                                    @class(array_merge(['size-14 md:size-16 lg:size-20'], $pickClasses($card)))>
                                    @include('pos::partials.table-card-inner', ['card' => $card])
                                </button>
                            @else
                                <a wire:key="unplaced-{{ $card['id'] }}"
                                    href="{{ url('/app/pos/session/' . $sessionId . '/table/' . $card['id']) }}" wire:navigate
                                    @class(array_merge(['size-14 md:size-16 lg:size-20'], $cardClasses($card, false)))>
                                    @include('pos::partials.table-card-inner', ['card' => $card])
                                </a>
                            @endif
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
        @endif
    @endif
</div>
