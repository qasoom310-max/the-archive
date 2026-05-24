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
    @if ($title !== '')
        <h2 class="mb-3 text-sm font-semibold text-chrome-800">{{ $title }}</h2>
    @endif

    <div class="flex gap-4 overflow-x-auto pb-4">
        @foreach ($columns as $colValue => $colLabel)
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
                            @if ($card?->image)
                                @php $imgPath = $record->getAttribute($card->image); @endphp
                                <div class="flex aspect-square w-full items-center justify-center bg-chrome-50">
                                    @if (is_string($imgPath) && $imgPath !== '')
                                        <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($imgPath) }}"
                                             alt="{{ $card ? $val($record, $card->title) : '' }}"
                                             class="size-full object-contain p-3">
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
                        <p class="px-2 py-6 text-center text-xs text-chrome-400">Drop cards here</p>
                    @endforelse
                </div>
            </div>
        @endforeach
    </div>
</div>
