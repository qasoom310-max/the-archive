@php
    $fmt = function ($value, string $format) {
        if ($value === null) return '—';
        // Enum-cast columns surface as enum objects — normalise first.
        $value = \App\Erp\Views\ValueFormat::label($value);
        return match ($format) {
            'number'   => is_numeric($value) ? number_format((float) $value, 2) : (string) $value,
            'date'     => $value instanceof \Illuminate\Support\Carbon ? $value->isoFormat('MMM D, YYYY') : (string) $value,
            'datetime' => $value instanceof \Illuminate\Support\Carbon ? $value->isoFormat('MMM D, YYYY HH:mm') : (string) $value,
            'bool'     => $value ? 'Yes' : 'No',
            default    => (string) $value,
        };
    };
@endphp

<div class="rounded-xl bg-white shadow-sm ring-1 ring-chrome-900/5">
    {{-- Toolbar / bulk-action bar --}}
    <div class="flex h-12 items-center justify-between border-b border-chrome-200 px-4">
        @if (count($selected) > 0)
            <div class="flex items-center gap-3">
                <span class="text-sm font-medium text-chrome-700">{{ count($selected) }} selected</span>
                @if ($canDelete)
                    <button wire:click="bulkDelete"
                        wire:confirm="Delete {{ count($selected) }} record(s)? This cannot be undone."
                        class="o-btn bg-red-600 text-white hover:bg-red-700">Delete</button>
                @endif
                <button wire:click="clearSelection" class="o-btn-ghost">Clear</button>
            </div>
        @else
            <h2 class="text-sm font-semibold text-chrome-800">{{ $title ?: 'Records' }}</h2>
            <span class="text-xs text-chrome-400">{{ $records->total() }} total</span>
        @endif
    </div>

    @if (count($columns) === 0)
        <p class="p-10 text-center text-sm text-chrome-400">No columns defined for this view.</p>
    @else
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-chrome-200 text-sm">
                <thead class="bg-chrome-50 text-xs uppercase tracking-wide text-chrome-500">
                    <tr>
                        <th class="w-10 px-4 py-2">
                            <input type="checkbox" wire:model.live="selectPage"
                                class="rounded border-chrome-300 text-primary-600 focus:ring-primary-500">
                        </th>
                        @foreach ($columns as $col)
                            @php
                                $active = collect($sorts)->firstWhere('field', $col->field);
                            @endphp
                            <th class="px-4 py-2 text-{{ $col->align }} {{ $col->sortable ? 'cursor-pointer select-none hover:text-chrome-800' : '' }}"
                                @if ($col->sortable) @click="$wire.sortBy('{{ $col->field }}', $event.shiftKey)" @endif>
                                <span class="inline-flex items-center gap-1">
                                    {{ $col->label }}
                                    @if ($active)
                                        <span class="text-primary-600">{{ $active['dir'] === 'asc' ? '▲' : '▼' }}</span>
                                    @endif
                                </span>
                            </th>
                        @endforeach
                    </tr>
                </thead>

                <tbody class="divide-y divide-chrome-100">
                    @forelse ($records as $record)
                        <tr wire:key="row-{{ $record->getKey() }}" class="hover:bg-chrome-50">
                            <td class="px-4 py-2">
                                <input type="checkbox" wire:model.live="selected" value="{{ $record->getKey() }}"
                                    class="rounded border-chrome-300 text-primary-600 focus:ring-primary-500">
                            </td>
                            @foreach ($columns as $col)
                                @php $value = $record->getAttribute($col->field); @endphp
                                <td class="px-4 py-2 text-{{ $col->align }} text-chrome-700">
                                    @if ($loop->first && $openUrl)
                                        <a href="{{ str_replace('{id}', (string) $record->getKey(), $openUrl) }}"
                                            wire:navigate class="font-medium text-primary-700 hover:underline">
                                            {{ $fmt($value, $col->format) }}
                                        </a>
                                    @elseif ($col->format === 'badge')
                                        <span class="o-chip bg-primary-50 text-primary-700">{{ $fmt($value, 'text') }}</span>
                                    @else
                                        {{ $fmt($value, $col->format) }}
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ count($columns) + 1 }}" class="px-4 py-10 text-center text-sm text-chrome-400">
                                No records.
                            </td>
                        </tr>
                    @endforelse
                </tbody>

                @if (count($aggregates) > 0)
                    <tfoot class="border-t-2 border-chrome-300 bg-chrome-50 font-semibold text-chrome-800">
                        <tr>
                            <td class="px-4 py-2"></td>
                            @foreach ($columns as $col)
                                <td class="px-4 py-2 text-{{ $col->align }}">
                                    @if (isset($aggregates[$col->field]))
                                        {{ number_format($aggregates[$col->field], 2) }}
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>

        <div class="border-t border-chrome-200 px-4 py-3">
            {{ $records->links() }}
        </div>
    @endif
</div>
