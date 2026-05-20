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
                                        @if ($val($record, $badge))
                                            <span class="o-chip bg-chrome-100 text-chrome-600">{{ $val($record, $badge) }}</span>
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
