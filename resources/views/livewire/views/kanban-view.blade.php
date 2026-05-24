@php
    $val = function ($record, ?string $field) {
        if ($field === null) return null;
        $v = $record->getAttribute($field);
        if ($v instanceof \Illuminate\Support\Carbon) return $v->isoFormat('MMM D');
        // Enum-cast columns (e.g. order state) → human label.
        return \App\Erp\Views\ValueFormat::label($v);
    };

    // Format dispatcher for the card's meta rows. `money` routes through
    // the active-currency formatter so BHD/USD render with correct symbol
    // + decimals; `number` falls back to `number_format` so non-money
    // numerics (stock counts) display without trailing 0.00 BD chrome.
    $metaFmt = function ($record, array $row) use ($val) {
        $v = $record->getAttribute($row['field']);
        if ($v === null || $v === '') return '—';
        return match ($row['format']) {
            'money'  => is_numeric($v) ? \App\Erp\Views\ValueFormat::money($v) : (string) $v,
            'number' => is_numeric($v) ? number_format((float) $v, 0) : (string) $v,
            'bool'   => $v ? __('Yes') : __('No'),
            default  => (string) ($val($record, $row['field']) ?? ''),
        };
    };
@endphp

<div>
    {{-- Toolbar: title on the start, free-text search on the end. The
         search input only renders when arch.searchable declares fields;
         empty arch list = no input (and the server silently ignores any
         smuggled `?search=` URL value, see KanbanView::applySearch).
         Wire model is `live.debounce.300ms` so each keystroke doesn't
         round-trip — server hits once typing settles. --}}
    @if ($title !== '' || $searchable)
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            @if ($title !== '')
                <h2 class="text-sm font-semibold text-chrome-800">{{ $title }}</h2>
            @else
                <span></span> {{-- spacer so search aligns end --}}
            @endif

            @if ($searchable)
                <div class="relative w-full max-w-xs">
                    <svg class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-chrome-400"
                        viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 1 0 3.5 9.74l3.38 3.38a1 1 0 0 0 1.42-1.42l-3.38-3.38A5.5 5.5 0 0 0 9 3.5ZM5.5 9a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0Z" clip-rule="evenodd"/>
                    </svg>
                    <input type="text"
                        wire:model.live.debounce.300ms="search"
                        placeholder="{{ __('Search…') }}"
                        aria-label="{{ __('Search') }}"
                        class="w-full rounded-md border border-chrome-300 bg-white py-1.5 ps-9 pe-8 text-sm text-chrome-800 placeholder:text-chrome-400 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/20">
                    @if ($search !== '')
                        <button type="button" wire:click="$set('search', '')"
                            aria-label="{{ __('Clear search') }}"
                            class="absolute end-2 top-1/2 -translate-y-1/2 rounded p-0.5 text-chrome-400 hover:text-chrome-700">
                            <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z"/>
                            </svg>
                        </button>
                    @endif
                </div>
            @endif
        </div>
    @endif

    {{-- Ungrouped boards (catalogue-style — no `group_by` in arch) render as a
         responsive grid so cards tile across the page instead of stacking in a
         single 288px swimlane. Drag-drop is a no-op when there are no stages
         to transition between, so the per-column drop handlers are skipped. --}}
    <div class="{{ $groupBy === null ? 'grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4' : 'flex gap-4 overflow-x-auto pb-4' }}">
        @foreach ($columns as $colValue => $colLabel)
            @if ($groupBy !== null)
                <div x-data="{ over: false }"
                    @dragover.prevent="over = true"
                    @dragleave="over = false"
                    @drop.prevent="over = false; $wire.moveCard($event.dataTransfer.getData('id'), @js((string) $colValue))"
                    :class="over ? 'bg-primary-50 ring-2 ring-primary-300' : 'bg-chrome-100'"
                    class="flex w-72 shrink-0 flex-col rounded-xl p-2 transition">

                    <div class="flex items-center justify-between px-2 py-1.5">
                        <span class="text-sm font-semibold text-chrome-700">{{ $colLabel }}</span>
                        <span class="rounded-full bg-white px-2 py-0.5 text-xs font-medium text-chrome-500">
                            {{ count($grouped[$colValue] ?? []) }}
                        </span>
                    </div>

                    <div class="flex min-h-16 flex-col gap-2 p-1">
            @endif

                    @forelse ($grouped[$colValue] ?? [] as $record)
                        @php
                            $isRotting = $rotting && $rotting->isRotting($record);
                            $stale = $isRotting ? $rotting->staleDays($record) : 0;
                        @endphp
                        <div draggable="true"
                            wire:key="card-{{ $record->getKey() }}"
                            @dragstart="$event.dataTransfer.setData('id', '{{ $record->getKey() }}')"
                            class="cursor-grab overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-chrome-900/5 active:cursor-grabbing
                                   {{ $isRotting ? 'border-l-4 border-red-500 opacity-90' : '' }}">
                            {{-- Image hero (Odoo-style). Only renders when the arch declared
                                 a `card.image` field AND the record has a value for it. Falls
                                 back to a neutral placeholder when the column is declared but
                                 empty, so cards on the same column stay the same height. --}}
                            {{-- Image hero — `aspect-[4/3]` is noticeably shorter than the
                                 previous `aspect-square` so a tile grid renders denser,
                                 closer to Odoo's POS catalogue. Native `loading="lazy"`
                                 layers a second lazy-load on top of the server-side
                                 windowing (only loads bytes when the row enters view). --}}
                            @if ($card?->image)
                                @php $imgPath = $record->getAttribute($card->image); @endphp
                                <div class="flex aspect-[4/3] w-full items-center justify-center bg-chrome-50">
                                    @if (is_string($imgPath) && $imgPath !== '')
                                        <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($imgPath) }}"
                                             alt="{{ $card ? $val($record, $card->title) : '' }}"
                                             loading="lazy" decoding="async"
                                             class="size-full object-contain p-2">
                                    @else
                                        <svg class="size-10 text-chrome-300" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M1 5.25A2.25 2.25 0 0 1 3.25 3h13.5A2.25 2.25 0 0 1 19 5.25v9.5A2.25 2.25 0 0 1 16.75 17H3.25A2.25 2.25 0 0 1 1 14.75v-9.5Zm1.5 5.81v3.69c0 .414.336.75.75.75h13.5a.75.75 0 0 0 .75-.75v-2.69l-2.22-2.219a.75.75 0 0 0-1.06 0L10 14.06l-3.969-3.97a.75.75 0 0 0-1.06 0L2.5 11.06ZM6.625 7a1.125 1.125 0 1 0 0 2.25 1.125 1.125 0 0 0 0-2.25Z" clip-rule="evenodd"/></svg>
                                    @endif
                                </div>
                            @endif

                            <div class="p-3">
                                <div class="flex items-start justify-between gap-2">
                                    <p class="text-sm font-semibold text-chrome-800">
                                        @if ($openUrl)
                                            <a href="{{ str_replace('{id}', (string) $record->getKey(), $openUrl) }}"
                                                wire:navigate class="text-primary-700 hover:underline">
                                                {{ $card ? $val($record, $card->title) : ('#' . $record->getKey()) }}
                                            </a>
                                        @else
                                            {{ $card ? $val($record, $card->title) : ('#' . $record->getKey()) }}
                                        @endif
                                    </p>
                                    @if ($isRotting)
                                        <span class="o-chip shrink-0 bg-red-50 text-red-600" title="Untouched for {{ $stale }} days">
                                            <span class="size-1.5 rounded-full bg-red-500"></span>{{ $stale }}d
                                        </span>
                                    @endif
                                </div>

                                @if ($card?->subtitle && $val($record, $card->subtitle))
                                    <p class="mt-0.5 text-xs text-chrome-500">{{ $val($record, $card->subtitle) }}</p>
                                @endif

                                {{-- Meta rows (label-on-start, value-on-end). The Odoo "price /
                                     stock" footer. Empty when the arch doesn't declare meta. --}}
                                @if ($card && count($card->meta) > 0)
                                    <dl class="mt-2 space-y-0.5 text-xs">
                                        @foreach ($card->meta as $row)
                                            <div class="flex items-baseline justify-between gap-2">
                                                <dt class="text-chrome-500">{{ $row['label'] !== null ? __($row['label']) : ucfirst(str_replace('_', ' ', $row['field'])) }}</dt>
                                                <dd class="font-medium text-chrome-800 tabular-nums">{{ $metaFmt($record, $row) }}</dd>
                                            </div>
                                        @endforeach
                                    </dl>
                                @endif

                                @if ($card && count($card->badges) > 0)
                                    <div class="mt-2 flex flex-wrap gap-1">
                                        @foreach ($card->badges as $badge)
                                            @php
                                                $rawBadge = $record->getAttribute($badge);
                                                $displayBadge = $val($record, $badge);
                                                $badgeColor = \App\Erp\Views\ValueFormat::color($rawBadge);
                                                $badgeClasses = match ($badgeColor) {
                                                    'emerald' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
                                                    'amber'   => 'bg-amber-50 text-amber-700 ring-amber-600/20',
                                                    'sky'     => 'bg-sky-50 text-sky-700 ring-sky-600/20',
                                                    'red'     => 'bg-red-50 text-red-700 ring-red-600/20',
                                                    'rose'    => 'bg-rose-50 text-rose-700 ring-rose-600/20',
                                                    'violet'  => 'bg-violet-50 text-violet-700 ring-violet-600/20',
                                                    'slate'   => 'bg-slate-100 text-slate-700 ring-slate-600/20',
                                                    default   => 'bg-chrome-100 text-chrome-600 ring-chrome-300/40',
                                                };
                                            @endphp
                                            @if ($displayBadge)
                                                <span class="o-chip {{ $badgeClasses }} ring-1 ring-inset">{{ $displayBadge }}</span>
                                            @endif
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        </div>
                    @empty
                        @if ($groupBy !== null)
                            <p class="px-2 py-6 text-center text-xs text-chrome-400">{{ __('Drop cards here') }}</p>
                        @endif
                    @endforelse

            @if ($groupBy !== null)
                    </div>
                </div>
            @endif
        @endforeach

        {{-- Lazy-load sentinel for ungrouped (catalogue) boards. An
             IntersectionObserver fires once when this element enters
             the viewport → $wire.loadMore() → server bumps $loaded by
             arch.per_page → re-render extends the grid. The wire:key
             includes $loaded so Livewire treats each re-rendered
             sentinel as a fresh DOM node, which re-runs Alpine's init
             and attaches a new observer (the previous one disconnects
             after firing). --}}
        @if ($groupBy === null && $hasMore)
            <div wire:key="kanban-sentinel-{{ $loaded }}"
                class="col-span-full"
                x-data="{
                    init() {
                        const obs = new IntersectionObserver((entries) => {
                            if (entries[0].isIntersecting) {
                                obs.disconnect();
                                $wire.loadMore();
                            }
                        }, { rootMargin: '200px' });
                        obs.observe($el);
                    }
                }">
                <div class="flex items-center justify-center gap-2 py-4 text-xs text-chrome-400"
                    wire:loading.flex wire:target="loadMore">
                    <svg class="size-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-dasharray="40 60"/>
                    </svg>
                    {{ __('Loading more…') }}
                </div>
            </div>
        @endif

        {{-- Empty state on an ungrouped board (catalogue) — only shown
             once the user has actually typed something. A pristine
             empty catalogue is rare enough that we don't surface a
             dedicated message; this row is targeted at "search
             returned nothing". --}}
        @if ($groupBy === null && $search !== '' && (count($grouped[''] ?? []) === 0))
            <div class="col-span-full">
                <p class="rounded-xl border border-dashed border-chrome-300 bg-white p-10 text-center text-sm text-chrome-400">
                    {{ __('No matches for') }} "<span class="font-medium text-chrome-700">{{ $search }}</span>"
                </p>
            </div>
        @endif
    </div>
</div>
