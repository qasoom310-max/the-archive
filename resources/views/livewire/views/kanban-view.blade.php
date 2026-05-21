@php
    $val = function ($record, ?string $field) {
        if ($field === null) return null;
        $v = $record->getAttribute($field);
        if ($v instanceof \Illuminate\Support\Carbon) return $v->isoFormat('MMM D');
        // Enum-cast columns (e.g. order state) → human label.
        return \App\Erp\Views\ValueFormat::label($v);
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
                            class="cursor-grab rounded-lg bg-white p-3 shadow-sm ring-1 ring-chrome-900/5 active:cursor-grabbing
                                   {{ $isRotting ? 'border-l-4 border-red-500 opacity-90' : '' }}">
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
                    @empty
                        <p class="px-2 py-6 text-center text-xs text-chrome-400">Drop cards here</p>
                    @endforelse
                </div>
            </div>
        @endforeach
    </div>
</div>
