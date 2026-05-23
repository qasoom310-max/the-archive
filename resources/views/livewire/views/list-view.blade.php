@php
    $fmt = function ($value, string $format) {
        if ($value === null) return '—';
        // Enum-cast columns surface as enum objects — normalise first.
        $value = \App\Erp\Views\ValueFormat::label($value);
        return match ($format) {
            'number'   => is_numeric($value) ? number_format((float) $value, 2) : (string) $value,
            'money'    => is_numeric($value) ? \App\Erp\Views\ValueFormat::money($value) : (string) $value,
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

    {{-- Filter chip row — only renders if the arch defines presets. Row of
         radio-style buttons: "All" plus one chip per preset, and (if the
         arch declares `custom_date_field`) a "Custom…" chip that pops a
         from/to picker. Clicking the active chip clears the filter
         (toggle). Aggregates below scope to the same filter so the
         footer total tracks what's shown. --}}
    @if (count($filters) > 0 || $customDateField !== null)
        <div class="flex flex-wrap items-center gap-2 border-b border-chrome-200 px-4 py-2">
            <button type="button" wire:click="applyFilterPreset('')"
                class="o-chip {{ $activeFilter === '' ? 'bg-primary-600 text-white' : 'bg-chrome-100 text-chrome-600 hover:bg-chrome-200' }}">
                All
            </button>
            @foreach ($filters as $filter)
                <button type="button" wire:click="applyFilterPreset('{{ $filter->name }}')"
                    class="o-chip {{ $activeFilter === $filter->name ? 'bg-primary-600 text-white' : 'bg-chrome-100 text-chrome-600 hover:bg-chrome-200' }}">
                    {{ $filter->label }}
                </button>
            @endforeach

            {{-- Custom range chip + popover. Alpine handles open/close +
                 click-outside; Livewire owns the From/To values (URL-bound
                 so a picked range is shareable). Apply is server-side —
                 it normalises both dates, swaps if reversed, and sets
                 filter='custom'. The chip itself shows the range while
                 active so the user can read "May 1 → May 7" at a glance. --}}
            @if ($customDateField !== null)
                <div x-data="{ open: false }" @click.outside="open = false" class="relative">
                    <button type="button" @click="open = !open"
                        class="o-chip flex items-center gap-1.5 {{ $activeFilter === 'custom' ? 'bg-primary-600 text-white' : 'bg-chrome-100 text-chrome-600 hover:bg-chrome-200' }}">
                        @if ($activeFilter === 'custom' && $customRangeLabel)
                            {{ $customRangeLabel }}
                        @else
                            Custom…
                        @endif
                        <svg class="size-3 opacity-70" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="m6 9 6 6 6-6" />
                        </svg>
                    </button>

                    <div x-show="open" x-cloak x-transition.opacity
                        class="absolute left-0 z-30 mt-2 w-72 origin-top-left rounded-lg border border-chrome-200 bg-white p-3 shadow-pop">
                        <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-chrome-500">
                            Pick a date range
                        </p>
                        <label class="mb-2 block">
                            <span class="block text-xs text-chrome-500">From</span>
                            <input type="date" wire:model="customFrom" class="o-input mt-1 text-sm">
                        </label>
                        <label class="mb-3 block">
                            <span class="block text-xs text-chrome-500">To</span>
                            <input type="date" wire:model="customTo" class="o-input mt-1 text-sm">
                        </label>
                        <div class="flex gap-2">
                            <button type="button" @click="open = false"
                                class="o-btn-ghost flex-1 justify-center text-sm">Cancel</button>
                            <button type="button"
                                wire:click="applyCustomRange"
                                @click="open = false"
                                class="o-btn-primary flex-1 justify-center text-sm">Apply</button>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    @endif

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
                                        {{-- Badge colour comes from the enum's color() method when
                                             the column is enum-backed (e.g. OrderState: emerald/amber/
                                             sky/red). The match is here, not in PHP, so every Tailwind
                                             class is a literal in the source the JIT scanner can see. --}}
                                        @php
                                            $badgeColor = \App\Erp\Views\ValueFormat::color($value);
                                            $badgeClasses = match ($badgeColor) {
                                                'emerald' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
                                                'amber'   => 'bg-amber-50 text-amber-700 ring-amber-600/20',
                                                'sky'     => 'bg-sky-50 text-sky-700 ring-sky-600/20',
                                                'red'     => 'bg-red-50 text-red-700 ring-red-600/20',
                                                'rose'    => 'bg-rose-50 text-rose-700 ring-rose-600/20',
                                                'violet'  => 'bg-violet-50 text-violet-700 ring-violet-600/20',
                                                'slate'   => 'bg-slate-100 text-slate-700 ring-slate-600/20',
                                                default   => 'bg-primary-50 text-primary-700 ring-primary-600/20',
                                            };
                                        @endphp
                                        <span class="o-chip {{ $badgeClasses }} ring-1 ring-inset">{{ $fmt($value, 'text') }}</span>
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
                                        {{ $col->format === 'money'
                                            ? \App\Erp\Views\ValueFormat::money($aggregates[$col->field])
                                            : number_format($aggregates[$col->field], 2) }}
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-2 border-t border-chrome-200 px-4 py-3">
            <label class="flex items-center gap-2 text-xs text-chrome-500">
                <span>{{ __('Rows per page') }}</span>
                <select wire:model.live="perPage"
                    class="rounded-md border-chrome-300 bg-white py-1 ps-2 pe-7 text-xs font-medium text-chrome-700 focus:border-primary-500 focus:ring-primary-500">
                    @foreach ($perPageOptions as $opt)
                        <option value="{{ $opt }}">{{ $opt }}</option>
                    @endforeach
                </select>
            </label>
            <div>{{ $records->links('vendor.pagination.compact') }}</div>
        </div>
    @endif
</div>
