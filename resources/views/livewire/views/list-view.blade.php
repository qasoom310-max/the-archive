@php
    $fmt = function ($value, string $format) {
        if ($value === null) return '—';
        // Enum-cast columns surface as enum objects — normalise first.
        $value = \App\Erp\Views\ValueFormat::label($value);
        return match ($format) {
            'number'   => is_numeric($value) ? number_format((float) $value, 2) : (string) $value,
            'money'    => is_numeric($value) ? \App\Erp\Views\ValueFormat::money($value) : (string) $value,
            'date'     => $value instanceof \Illuminate\Support\Carbon ? $value->isoFormat('DD-MMM-YYYY') : (string) $value,
            'datetime' => $value instanceof \Illuminate\Support\Carbon ? $value->isoFormat('DD-MMM-YYYY HH:mm') : (string) $value,
            'bool'     => $value ? 'Yes' : 'No',
            default    => (string) $value,
        };
    };
@endphp

<div class="rounded-xl bg-white shadow-sm ring-1 ring-chrome-900/5">
    {{-- Toolbar / bulk-action bar. Phone (`<sm`): stacks vertically so the
         title sits above the search/totals/column-picker row instead of
         overflowing the viewport. Tablet+ (`sm`): single horizontal bar
         (the original h-12 layout). --}}
    <div class="flex flex-col items-stretch gap-2 border-b border-chrome-200 px-3 py-2 sm:h-12 sm:flex-row sm:items-center sm:justify-between sm:px-4 sm:py-0">
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
        @endif

        <div class="flex items-center justify-between gap-2 sm:ms-auto sm:justify-end sm:gap-3">
            {{-- Free-text search bar — only renders when the arch declares
                 a `searchable` field list. Styled as a primary-themed pill:
                 muted chrome-100 base that lifts to white on focus with a
                 primary-500 ring; magnifying glass leading icon; clear (×)
                 trailing button that wipes the input + resets the page.
                 wire:model.live.debounce.300ms = no Search button needed,
                 results stream as the user types without spamming the
                 server on every keystroke. --}}
            @if ($searchable)
                <div x-data="{ q: @entangle('search').live }"
                     class="group relative">
                    <span class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-3 text-chrome-400 group-focus-within:text-primary-600 transition-colors">
                        <svg class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 1 0 0 11 5.5 5.5 0 0 0 0-11ZM2 9a7 7 0 1 1 12.452 4.391l3.328 3.329a.75.75 0 1 1-1.06 1.06l-3.329-3.328A7 7 0 0 1 2 9Z" clip-rule="evenodd"/></svg>
                    </span>
                    <input type="text"
                           wire:model.live.debounce.300ms="search"
                           x-model="q"
                           placeholder="{{ __('Search…') }}"
                           aria-label="{{ __('Search') }}"
                           class="w-full rounded-full border-0 bg-chrome-100 py-1.5 ps-9 pe-9 text-sm text-chrome-800 placeholder:text-chrome-400 transition-all focus:bg-white focus:shadow-sm focus:ring-2 focus:ring-primary-500 focus:placeholder:text-chrome-300 sm:w-44 sm:focus:w-64">
                    <button type="button"
                            x-show="q.length > 0"
                            x-cloak
                            @click="q = ''; $wire.set('search', '')"
                            aria-label="{{ __('Clear search') }}"
                            class="absolute inset-y-0 end-0 flex items-center pe-3 text-chrome-400 hover:text-primary-600">
                        <svg class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z"/></svg>
                    </button>
                </div>
            @endif

            <span class="text-xs text-chrome-400">{{ $records->total() }} {{ __('total') }}</span>

            {{-- Column-picker — lives in the toolbar (not in a table-header
                 cell, which would consume column space + re-render with the
                 rest of <thead> on every Livewire morph). wire:ignore on the
                 dropdown keeps our drag listeners attached after toggleColumn
                 round-trips; checkbox states update natively via Alpine's
                 click handler before the round-trip even fires. --}}
            <div class="relative"
                 x-data="{ open: @entangle('columnPickerOpen').live }"
                 @keydown.escape.window="open = false"
                 @click.outside="open = false">
                <button type="button" @click="open = !open"
                        aria-haspopup="true" :aria-expanded="open"
                        aria-label="{{ __('Configure columns') }}"
                        class="flex size-7 items-center justify-center rounded text-chrome-500 hover:bg-chrome-100 hover:text-chrome-700"
                        title="{{ __('Configure columns') }}">
                    {{-- Heroicons mini "adjustments-horizontal" --}}
                    <svg class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M2 5.75A.75.75 0 0 1 2.75 5h7.5a.75.75 0 0 1 0 1.5h-7.5A.75.75 0 0 1 2 5.75ZM14 5.75a.75.75 0 0 1 .75-.75h2.5a.75.75 0 0 1 0 1.5h-2.5a.75.75 0 0 1-.75-.75ZM2 10a.75.75 0 0 1 .75-.75h2.5a.75.75 0 0 1 0 1.5h-2.5A.75.75 0 0 1 2 10Zm7 0a.75.75 0 0 1 .75-.75h7.5a.75.75 0 0 1 0 1.5h-7.5A.75.75 0 0 1 9 10Zm-7 4.25a.75.75 0 0 1 .75-.75h7.5a.75.75 0 0 1 0 1.5h-7.5a.75.75 0 0 1-.75-.75Zm12 0a.75.75 0 0 1 .75-.75h2.5a.75.75 0 0 1 0 1.5h-2.5a.75.75 0 0 1-.75-.75Z" clip-rule="evenodd"/><path d="M9.25 4a1.75 1.75 0 1 0 0 3.5 1.75 1.75 0 0 0 0-3.5ZM13.25 8.25a1.75 1.75 0 1 0 0 3.5 1.75 1.75 0 0 0 0-3.5ZM9.25 12.5a1.75 1.75 0 1 0 0 3.5 1.75 1.75 0 0 0 0-3.5Z"/></svg>
                </button>

                <div x-show="open" x-cloak x-transition.origin.top.end
                     wire:ignore
                     class="absolute end-0 z-30 mt-2 w-64 max-w-[calc(100vw-1.5rem)] origin-top-end rounded-lg bg-white p-2 text-start text-xs font-normal normal-case text-chrome-700 shadow-pop ring-1 ring-chrome-900/5">
                    <p class="px-2 py-1 text-[11px] font-semibold uppercase tracking-wide text-chrome-400">{{ __('Columns') }}</p>
                    <ul x-data="listColumnPicker" x-init="init($el, $wire)"
                        class="max-h-72 space-y-0.5 overflow-y-auto py-1">
                        @foreach ($allColumns as $col)
                            @php
                                $checked = ! in_array($col->field, $hiddenColumns, true);
                            @endphp
                            <li data-col="{{ $col->field }}" draggable="true"
                                class="flex cursor-grab items-center gap-2 rounded px-2 py-1.5 hover:bg-chrome-50 active:cursor-grabbing">
                                <svg class="size-3.5 shrink-0 text-chrome-300" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M7 4a1 1 0 1 1-2 0 1 1 0 0 1 2 0Zm0 6a1 1 0 1 1-2 0 1 1 0 0 1 2 0Zm-1 7a1 1 0 1 0 0-2 1 1 0 0 0 0 2Zm9-13a1 1 0 1 1-2 0 1 1 0 0 1 2 0Zm-1 7a1 1 0 1 0 0-2 1 1 0 0 0 0 2Zm1 5a1 1 0 1 1-2 0 1 1 0 0 1 2 0Z"/></svg>
                                <label class="flex flex-1 cursor-pointer items-center gap-2 normal-case tracking-normal">
                                    <input type="checkbox" {{ $checked ? 'checked' : '' }}
                                           @change="$wire.toggleColumn('{{ $col->field }}')"
                                           class="rounded border-chrome-300 text-primary-600 focus:ring-primary-500">
                                    <span>{{ $col->label }}</span>
                                </label>
                            </li>
                        @endforeach
                    </ul>
                    <p class="border-t border-chrome-100 px-2 pb-1 pt-1.5 text-[11px] text-chrome-400">{{ __('Drag rows to reorder. Click a checkbox to show/hide.') }}</p>
                </div>
            </div>
        </div>
    </div>

    {{-- Dynamic filter chip rows — one row per arch-declared
         `filters_dynamic` group (e.g. Category for POS products). Each row
         loads its options from the related model (no DB work in the blade
         itself; ListView::loadDynamicFilterOptions did the query). Clicking
         the active chip clears the group (visual "All" behaviour). --}}
    @foreach ($dynamicFilterDefs as $def)
        @php $options = $dynamicFilterOptions[$def->name] ?? []; @endphp
        @if (count($options) > 0)
            <div class="flex flex-wrap items-center gap-1.5 border-b border-chrome-100 px-3 py-2 sm:px-4">
                <span class="me-1 text-xs font-semibold uppercase tracking-wide text-chrome-400">{{ __($def->label) }}</span>
                <button type="button" wire:click="applyDynamicFilter('{{ $def->name }}', '')"
                    class="o-chip {{ ! isset($activeDynamicFilters[$def->name]) ? 'bg-primary-400 text-chrome-900' : 'bg-chrome-100 text-chrome-600 hover:bg-chrome-200' }}">
                    {{ __('All') }}
                </button>
                @foreach ($options as $opt)
                    @php $isActive = isset($activeDynamicFilters[$def->name]) && (string) $activeDynamicFilters[$def->name] === $opt['value']; @endphp
                    <button type="button" wire:click="applyDynamicFilter('{{ $def->name }}', '{{ $opt['value'] }}')"
                        class="o-chip {{ $isActive ? 'bg-primary-400 text-chrome-900' : 'bg-chrome-100 text-chrome-600 hover:bg-chrome-200' }}">
                        {{ $opt['label'] }}
                    </button>
                @endforeach
            </div>
        @endif
    @endforeach

    {{-- Filter chip row — only renders if the arch defines presets. Row of
         radio-style buttons: "All" plus one chip per preset, and (if the
         arch declares `custom_date_field`) a "Custom…" chip that pops a
         from/to picker. Clicking the active chip clears the filter
         (toggle). Aggregates below scope to the same filter so the
         footer total tracks what's shown. --}}
    @if (count($filters) > 0 || $customDateField !== null)
        <div class="flex flex-wrap items-center gap-2 border-b border-chrome-200 px-4 py-2">
            <button type="button" wire:click="applyFilterPreset('')"
                class="o-chip {{ $activeFilter === '' ? 'bg-primary-400 text-chrome-900' : 'bg-chrome-100 text-chrome-600 hover:bg-chrome-200' }}">
                All
            </button>
            @foreach ($filters as $filter)
                <button type="button" wire:click="applyFilterPreset('{{ $filter->name }}')"
                    class="o-chip {{ $activeFilter === $filter->name ? 'bg-primary-400 text-chrome-900' : 'bg-chrome-100 text-chrome-600 hover:bg-chrome-200' }}">
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
                        class="o-chip flex items-center gap-1.5 {{ $activeFilter === 'custom' ? 'bg-primary-400 text-chrome-900' : 'bg-chrome-100 text-chrome-600 hover:bg-chrome-200' }}">
                        @if ($activeFilter === 'custom' && $customRangeLabel)
                            {{ $customRangeLabel }}
                        @else
                            Custom…
                        @endif
                        <svg class="size-3 opacity-70" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="m6 9 6 6 6-6" />
                        </svg>
                    </button>

                    {{-- Logical end-0/origin-top-end (RTL-correct) + viewport cap so
                         this popover can't overflow off a phone when its "Custom…"
                         chip wraps to the right of the filter row. --}}
                    <div x-show="open" x-cloak x-transition.opacity
                        class="absolute end-0 z-30 mt-2 w-72 max-w-[calc(100vw-1.5rem)] origin-top-end rounded-lg border border-chrome-200 bg-white p-3 shadow-pop">
                        <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-chrome-500">
                            Pick a date range
                        </p>
                        <label class="mb-2 block">
                            <span class="block text-xs text-chrome-500">From</span>
                            <x-date-field wire:model="customFrom" class="o-input mt-1 text-sm" />
                        </label>
                        <label class="mb-3 block">
                            <span class="block text-xs text-chrome-500">To</span>
                            <x-date-field wire:model="customTo" class="o-input mt-1 text-sm" />
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
                                    @elseif ($col->format === 'toggle')
                                        {{-- Interactive switch. wire:click is gated server-side
                                             on Write permission + arch whitelist (only columns
                                             with format=toggle are flippable) so a tampered
                                             click on `id`/`is_admin` can't escape. --}}
                                        @php $on = (bool) $value; @endphp
                                        <button type="button"
                                                wire:click="toggleBoolean({{ $record->getKey() }}, '{{ $col->field }}')"
                                                wire:loading.attr="disabled"
                                                role="switch"
                                                aria-checked="{{ $on ? 'true' : 'false' }}"
                                                aria-label="{{ $col->label }}"
                                                title="{{ $col->label }}"
                                                class="relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-1 {{ $on ? 'bg-primary-500' : 'bg-chrome-300' }}">
                                            <span class="inline-block size-4 transform rounded-full bg-white shadow transition-transform {{ $on ? 'translate-x-[1.125rem]' : 'translate-x-0.5' }}"></span>
                                        </button>
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
