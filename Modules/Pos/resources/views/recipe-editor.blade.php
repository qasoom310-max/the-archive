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
                    <th class="py-1 text-left">Component</th>
                    <th class="py-1 text-right">Qty / unit</th>
                    <th class="py-1 text-right">Component stock</th>
                    <th class="py-1"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-chrome-100">
                @foreach ($lines as $line)
                    <tr wire:key="recipe-{{ $line->id }}">
                        <td class="py-1.5 text-chrome-800">{{ $line->component?->name ?? '—' }}</td>
                        <td class="py-1.5 text-right text-chrome-600">{{ rtrim(rtrim(number_format($line->quantity_consumed, 3), '0'), '.') }}</td>
                        <td class="py-1.5 text-right text-chrome-500">
                            {{ rtrim(rtrim(number_format($line->component?->stock_on_hand ?? 0, 3), '0'), '.') }}
                        </td>
                        <td class="py-1.5 text-right">
                            <button wire:click="removeLine({{ $line->id }})"
                                class="text-xs text-red-500 hover:underline">remove</button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @php
        $componentsJs = $components->map(fn ($c): array => ['id' => (int) $c->id, 'name' => (string) $c->name])->values();
    @endphp
    <div class="mt-4 flex flex-wrap items-end gap-2 border-t border-chrome-100 pt-4">
        <div class="min-w-48 flex-1">
            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Component') }}</label>
            {{-- Searchable combobox bound to componentId, with an Odoo-style
                 "Create" footer that opens the shared new-product modal. The
                 panel is position:fixed so it escapes any overflow clipping. --}}
            <div x-data="{
                    open: false,
                    coords: { top: 'auto', bottom: 'auto', left: 0, width: 0, maxH: 256 },
                    products: @js($componentsJs),
                    selected: $wire.entangle('componentId'),
                    search: '',
                    place() {
                        const r = $refs.input.getBoundingClientRect();
                        const vh = window.innerHeight;
                        const spaceBelow = vh - r.bottom;
                        // Flip up when there's more room above (input near the
                        // page bottom) so the panel never opens off-screen.
                        const below = spaceBelow >= 280 || spaceBelow >= r.top;
                        const maxH = Math.max(120, Math.min(256, (below ? spaceBelow : r.top) - 12));
                        this.coords = below
                            ? { left: r.left, width: r.width, top: (r.bottom + 4) + 'px', bottom: 'auto', maxH }
                            : { left: r.left, width: r.width, top: 'auto', bottom: (vh - r.top + 4) + 'px', maxH };
                    },
                    openPanel() { this.search = ''; this.place(); this.open = true; },
                    filtered() {
                        const s = this.search.trim().toLowerCase();
                        const list = s === '' ? this.products : this.products.filter(p => p.name.toLowerCase().includes(s));
                        return list.slice(0, 50);
                    },
                    displayName() {
                        const m = this.products.find(p => p.id === this.selected);
                        return m ? m.name : '';
                    },
                    choose(p) { this.selected = p ? p.id : null; this.search = ''; this.open = false; },
                }"
                @product-created.window="products.push({ id: $event.detail.id, name: $event.detail.name }); selected = $event.detail.id"
                @click.outside="open = false"
                @scroll.window.passive="if (open) place()"
                @resize.window="if (open) place()"
                class="relative">
                <input type="text" x-ref="input"
                    :value="open ? search : displayName()"
                    @focus="openPanel()" @click="openPanel()"
                    @input="search = $event.target.value; open = true"
                    @keydown.escape.stop="open = false"
                    placeholder="{{ __('Search a product…') }}"
                    autocomplete="off" class="o-input">
                <div x-show="open" x-cloak
                    :style="`left:${coords.left}px; width:${coords.width}px; top:${coords.top}; bottom:${coords.bottom}; max-height:${coords.maxH}px;`"
                    class="fixed z-50 overflow-auto rounded-lg border border-chrome-200 bg-white py-1 shadow-pop">
                    <template x-for="p in filtered()" :key="p.id">
                        <button type="button" @click="choose(p)"
                            class="flex w-full items-center px-3 py-1.5 text-start text-sm text-chrome-700 hover:bg-primary-50"
                            x-text="p.name"></button>
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
            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Qty / unit') }}</label>
            <input type="number" step="0.001" min="0" wire:model="quantity" class="o-input">
        </div>
        <button wire:click="addLine" class="o-btn-primary">{{ __('Add component') }}</button>
    </div>

    {{-- Inline "new product" modal — shared with the Purchases bill editor. --}}
    @include('pos::partials.new-product-modal')
</div>
