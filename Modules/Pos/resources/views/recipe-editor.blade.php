@php
    $yield = $product?->theoreticalYield();
@endphp

<div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5">
    <div class="mb-3 flex items-center justify-between">
        <div>
            <h2 class="text-sm font-semibold text-chrome-800">Recipe (static ingredient consumption)</h2>
            <p class="text-xs text-chrome-500">Each sale of one unit decrements these components from stock.</p>
        </div>
        @if ($yield !== null)
            <span class="o-chip {{ $yield <= 0 ? 'bg-red-50 text-red-600' : 'bg-emerald-50 text-emerald-700' }}">
                {{ $yield }} available serving{{ $yield === 1 ? '' : 's' }}
            </span>
        @endif
    </div>

    @if ($lines->isEmpty())
        <p class="rounded-lg border border-dashed border-chrome-300 p-4 text-center text-xs text-chrome-400">
            No recipe — this product does not consume any components.
        </p>
    @else
        <table class="min-w-full divide-y divide-chrome-100 text-sm">
            <thead class="text-xs uppercase tracking-wide text-chrome-400">
                <tr>
                    <th class="py-1 text-start">{{ __('Component') }}</th>
                    <th class="py-1 text-end">{{ __('Qty / unit') }}</th>
                    <th class="py-1 text-end">{{ __('Component stock') }}</th>
                    <th class="py-1"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-chrome-100">
                @foreach ($lines as $line)
                    @php $u = $line->componentUnit(); @endphp
                    <tr wire:key="recipe-{{ $line->id }}">
                        <td class="py-1.5 text-chrome-800">
                            {{ $line->componentName() ?? '—' }}
                            @if ($line->isCondiment())
                                <span class="ms-1 rounded-full bg-primary-100 px-2 py-0.5 text-[10px] font-medium text-primary-700">{{ __('Condiment') }}</span>
                            @elseif ($line->isIngredient())
                                <span class="ms-1 rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-medium text-emerald-700">{{ __('Ingredient') }}</span>
                            @endif
                        </td>
                        {{-- Editable per-unit quantity (fractional), in the component's
                             own unit — e.g. 0.25 L of milk per cup. Saves on change. --}}
                        <td class="py-1.5 text-end">
                            <span class="inline-flex items-center justify-end gap-1">
                                <input type="number" step="0.001" min="0"
                                    value="{{ rtrim(rtrim(number_format($line->quantity_consumed, 3), '0'), '.') }}"
                                    @change="$wire.updateLineQuantity({{ $line->id }}, $event.target.value)"
                                    class="o-input w-24 text-end">
                                @if ($u !== '')<span class="text-xs text-chrome-400">{{ $u }}</span>@endif
                            </span>
                        </td>
                        <td class="py-1.5 text-end text-chrome-500">
                            {{ rtrim(rtrim(number_format($line->componentStock() ?? 0, 3), '0'), '.') }}{{ $u !== '' ? ' ' . $u : '' }}
                        </td>
                        <td class="py-1.5 text-end">
                            <button wire:click="removeLine({{ $line->id }})"
                                class="text-xs text-red-500 hover:underline">{{ __('remove') }}</button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @php
        $componentsJs = $componentOptions->map(fn (array $o): array => ['key' => $o['key'], 'name' => $o['name'], 'type' => $o['type'], 'unit' => $o['unit'] ?? ''])->values();
    @endphp
    <div class="mt-4 flex flex-wrap items-end gap-2 border-t border-chrome-100 pt-4">
        <div class="min-w-48 flex-1">
            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Component') }}</label>
            {{-- Searchable combobox over products + condiments (composite key
                 p:{id} / c:{id}), with an Odoo-style "Create" footer that opens
                 the shared new-product modal. Panel is position:fixed (flips up
                 near the page bottom) so it escapes overflow clipping. --}}
            <div x-data="{
                    open: false,
                    coords: { top: 'auto', bottom: 'auto', left: 0, width: 0, maxH: 256 },
                    items: @js($componentsJs),
                    selected: $wire.entangle('componentKey'),
                    search: '',
                    place() {
                        const r = $refs.input.getBoundingClientRect();
                        const vh = window.innerHeight;
                        const spaceBelow = vh - r.bottom;
                        const below = spaceBelow >= 280 || spaceBelow >= r.top;
                        const maxH = Math.max(120, Math.min(256, (below ? spaceBelow : r.top) - 12));
                        this.coords = below
                            ? { left: r.left, width: r.width, top: (r.bottom + 4) + 'px', bottom: 'auto', maxH }
                            : { left: r.left, width: r.width, top: 'auto', bottom: (vh - r.top + 4) + 'px', maxH };
                    },
                    openPanel() { this.search = ''; this.place(); this.open = true; },
                    filtered() {
                        const s = this.search.trim().toLowerCase();
                        const list = s === '' ? this.items : this.items.filter(p => p.name.toLowerCase().includes(s));
                        return list.slice(0, 50);
                    },
                    displayName() {
                        const m = this.items.find(p => p.key === this.selected);
                        return m ? m.name : '';
                    },
                    choose(p) { this.selected = p ? p.key : null; this.search = ''; this.open = false; },
                }"
                @product-created.window="items.push({ key: 'p:' + $event.detail.id, name: $event.detail.name, type: 'product' }); selected = 'p:' + $event.detail.id"
                @click.outside="open = false"
                @scroll.window.passive="if (open) place()"
                @resize.window="if (open) place()"
                class="relative">
                <input type="text" x-ref="input"
                    :value="open ? search : displayName()"
                    @focus="openPanel()" @click="openPanel()"
                    @input="search = $event.target.value; open = true"
                    @keydown.escape.stop="open = false"
                    placeholder="{{ __('Search a product, condiment or ingredient…') }}"
                    autocomplete="off" class="o-input">
                <div x-show="open" x-cloak
                    :style="`left:${coords.left}px; width:${coords.width}px; top:${coords.top}; bottom:${coords.bottom}; max-height:${coords.maxH}px;`"
                    class="fixed z-50 overflow-auto rounded-lg border border-chrome-200 bg-white py-1 shadow-pop">
                    <template x-for="p in filtered()" :key="p.key">
                        <button type="button" @click="choose(p)"
                            class="flex w-full items-center justify-between gap-2 px-3 py-1.5 text-start text-sm text-chrome-700 hover:bg-primary-50">
                            <span class="flex items-center gap-1">
                                <span x-text="p.name"></span>
                                <span x-show="p.unit" class="text-[10px] text-chrome-400" x-text="p.unit"></span>
                            </span>
                            <span x-show="p.type === 'product'"
                                class="shrink-0 rounded-full bg-chrome-100 px-2 py-0.5 text-[10px] font-medium text-chrome-600">{{ __('Product') }}</span>
                            <span x-show="p.type === 'condiment'"
                                class="shrink-0 rounded-full bg-primary-100 px-2 py-0.5 text-[10px] font-medium text-primary-700">{{ __('Condiment') }}</span>
                            <span x-show="p.type === 'ingredient'"
                                class="shrink-0 rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-medium text-emerald-700">{{ __('Ingredient') }}</span>
                        </button>
                    </template>
                    <template x-if="filtered().length === 0">
                        <p class="px-3 py-1.5 text-sm text-chrome-400">{{ __('No products found') }}</p>
                    </template>
                    @if ($canCreateProduct)
                        <div class="mt-1 border-t border-chrome-100 pt-1">
                            <button type="button" @click="$wire.openProductModal(search)"
                                class="flex w-full items-center gap-1 px-3 py-1.5 text-start text-sm font-medium text-primary-700 hover:bg-primary-50">
                                <svg class="size-4 shrink-0" viewBox="0 0 20 20" fill="currentColor"><path d="M10 5a1 1 0 0 1 1 1v3h3a1 1 0 1 1 0 2h-3v3a1 1 0 1 1-2 0v-3H6a1 1 0 1 1 0-2h3V6a1 1 0 0 1 1-1Z"/></svg>
                                <span x-show="search.trim() === ''">{{ __('New product') }}</span>
                                <span x-show="search.trim() !== ''" x-cloak>{{ __('Create') }} "<span x-text="search"></span>"</span>
                            </button>
                        </div>
                    @endif
                </div>
            </div>
        </div>
        <div class="w-32">
            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">
                {{ __('Qty / unit') }}@if ($selectedUnit !== '') <span class="text-chrome-400">({{ $selectedUnit }})</span>@endif
            </label>
            <input type="number" step="0.001" min="0" wire:model="quantity" class="o-input">
        </div>
        <button wire:click="addLine" class="o-btn-primary">{{ __('Add component') }}</button>
    </div>

    {{-- Inline "new product" modal — shared with the Purchases bill editor. --}}
    @include('pos::partials.new-product-modal')
</div>
