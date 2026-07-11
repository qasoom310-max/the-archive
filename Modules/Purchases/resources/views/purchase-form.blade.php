@php
    use App\Erp\Money\Currencies;
    // Client-side component list (products + condiments + ingredients) for the
    // per-line searchable comboboxes. Each carries a composite key
    // ("p:{id}" / "c:{id}" / "i:{id}") and a `type` so the dropdown can badge
    // condiments / ingredients (so two same-named rows are distinguishable).
    $componentsJs = $components->values();
@endphp
<div class="mx-auto max-w-4xl p-4 sm:p-6"
    x-data="{
        components: @js($componentsJs),
        filterComponents(q) {
            const s = (q || '').trim().toLowerCase();
            const list = s === '' ? this.components : this.components.filter(c => c.name.toLowerCase().includes(s));
            return list.slice(0, 50);
        },
    }"
    @product-created.window="components.push({ key: 'p:' + $event.detail.id, name: $event.detail.name, type: 'product' })">
    <div class="mb-4 flex items-center gap-2 text-sm text-chrome-500">
        <a href="{{ url('/app/purchases/purchase') }}" wire:navigate class="hover:text-primary-700">{{ __('Purchases') }}</a>
        <span>/</span>
        <span class="font-medium text-chrome-700">{{ $reference ?? __('New purchase') }}</span>
    </div>

    @if ($justConfirmed)
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            <p class="font-semibold">{{ __('Bill confirmed.') }}</p>
            <p class="mt-0.5">{{ __('POS stock and warehouse stock were both increased, and the accounting entry was posted.') }}</p>
        </div>
    @endif

    <form wire:submit.prevent="save" class="rounded-xl bg-white shadow-sm ring-1 ring-chrome-900/5">
        <div class="flex h-12 items-center justify-between border-b border-chrome-200 px-4">
            <h2 class="text-sm font-semibold text-chrome-800">
                {{ $isConfirmed ? __('Purchase') : ($reference ? __('Edit purchase') : __('New purchase')) }}
            </h2>
            <div class="flex items-center gap-2">
                @if ($isConfirmed)
                    <span class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-semibold text-emerald-700">
                        <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.704 5.296a1 1 0 0 1 0 1.408l-7.5 7.5a1 1 0 0 1-1.408 0l-3.5-3.5a1 1 0 0 1 1.408-1.408L8.5 12.09l6.796-6.795a1 1 0 0 1 1.408 0Z" clip-rule="evenodd"/></svg>
                        {{ __('Confirmed') }}
                    </span>
                @else
                    @if ($canWrite || $canCreate)
                        <button type="submit"
                            class="rounded-md px-3 py-1.5 text-sm font-medium text-chrome-600 ring-1 ring-chrome-300 hover:bg-chrome-50">
                            {{ __('Save draft') }}
                        </button>
                        <button type="button" wire:click="confirm" wire:loading.attr="disabled" wire:target="confirm"
                            class="o-btn-primary disabled:cursor-not-allowed disabled:opacity-60">
                            {{ __('Confirm') }}
                        </button>
                    @endif
                @endif
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4 p-6 sm:grid-cols-2">
            <div>
                <div class="mb-1 flex items-center justify-between gap-2">
                    <label class="block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Vendor') }}</label>
                    @unless ($isConfirmed)
                        @if ($canWrite || $canCreate)
                            {{-- Inline vendor create — no trip to Contacts. --}}
                            <button type="button" wire:click="openVendorModal"
                                class="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-xs font-medium text-primary-700 hover:bg-primary-50">
                                <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor"><path d="M10 5a1 1 0 0 1 1 1v3h3a1 1 0 1 1 0 2h-3v3a1 1 0 1 1-2 0v-3H6a1 1 0 1 1 0-2h3V6a1 1 0 0 1 1-1Z"/></svg>
                                {{ __('New vendor') }}
                            </button>
                        @endif
                    @endunless
                </div>
                <select wire:model="form.partner_id" @disabled($isConfirmed) class="o-input">
                    <option value="">—</option>
                    @foreach ($vendors as $vendor)
                        <option value="{{ $vendor->id }}">{{ $vendor->name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Date') }} <span class="text-red-500">*</span></label>
                <input type="date" wire:model="form.date" @disabled($isConfirmed) class="o-input">
                @error('form.date') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Purchase name') }}</label>
                <input type="text" wire:model="form.name" @disabled($isConfirmed)
                    placeholder="{{ __('e.g. Weekly coffee restock') }}" class="o-input">
                @error('form.name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Expiry date') }}</label>
                <input type="date" wire:model="form.expiry_date" @disabled($isConfirmed) class="o-input">
                @error('form.expiry_date') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Reference') }}</label>
                <input type="text" wire:model="form.reference" @disabled($isConfirmed)
                    placeholder="{{ __('Auto (or vendor invoice no.)') }}" class="o-input">
            </div>

            <div class="flex items-end">
                <label class="inline-flex items-center gap-2">
                    <input type="checkbox" wire:model="form.is_stock_purchase" @disabled($isConfirmed)
                        class="rounded border-chrome-300 text-primary-600 focus:ring-primary-500">
                    <span class="text-sm text-chrome-600">{{ __('Stock purchase (adds to inventory)') }}</span>
                </label>
            </div>
        </div>

        {{-- Lines --}}
        <div class="border-t border-chrome-200 px-6 py-4">
            <div class="mb-2 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-chrome-700">{{ __('Products') }}</h3>
                @unless ($isConfirmed)
                    <button type="button" wire:click="addLine"
                        class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium text-primary-700 hover:bg-primary-50">
                        <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10 5a1 1 0 0 1 1 1v3h3a1 1 0 1 1 0 2h-3v3a1 1 0 1 1-2 0v-3H6a1 1 0 1 1 0-2h3V6a1 1 0 0 1 1-1Z"/></svg>
                        {{ __('Add line') }}
                    </button>
                @endunless
            </div>

            @error('lines') <p class="mb-2 text-xs text-red-600">{{ $message }}</p> @enderror

            <div class="overflow-hidden rounded-lg ring-1 ring-chrome-200">
                <table class="w-full text-sm">
                    <thead class="bg-chrome-50 text-xs uppercase tracking-wide text-chrome-500">
                        <tr>
                            <th class="px-3 py-2 text-start font-semibold">{{ __('Product') }}</th>
                            <th class="px-3 py-2 text-end font-semibold">{{ __('Qty') }}</th>
                            <th class="px-3 py-2 text-end font-semibold">{{ __('Unit cost') }}</th>
                            <th class="px-3 py-2 text-end font-semibold">{{ __('Subtotal') }}</th>
                            @unless ($isConfirmed) <th class="w-10 px-3 py-2"></th> @endunless
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-chrome-100">
                        @foreach ($lines as $i => $line)
                            @php
                                $selName = '';
                                if (($line['component'] ?? '') !== '') {
                                    $sel = $components->firstWhere('key', $line['component']);
                                    $selName = $sel ? (string) $sel['name'] : '';
                                }
                            @endphp
                            <tr wire:key="line-{{ $i }}">
                                <td class="px-3 py-2">
                                    @if ($isConfirmed)
                                        <div class="o-input bg-chrome-50 text-chrome-700">{{ $selName !== '' ? $selName : '—' }}</div>
                                    @else
                                        {{-- Searchable product picker (type to filter) with an
                                             Odoo-style "Create" footer that opens the new-product modal. --}}
                                        <div x-data="{
                                                open: false,
                                                coords: { top: 'auto', bottom: 'auto', left: 0, width: 0, maxH: 256 },
                                                selectedName: @js($selName),
                                                search: @js($selName),
                                                place() {
                                                    const r = this.$refs.input.getBoundingClientRect();
                                                    const vh = window.innerHeight;
                                                    const spaceBelow = vh - r.bottom;
                                                    const below = spaceBelow >= 280 || spaceBelow >= r.top;
                                                    const maxH = Math.max(120, Math.min(256, (below ? spaceBelow : r.top) - 12));
                                                    this.coords = below
                                                        ? { left: r.left, width: r.width, top: (r.bottom + 4) + 'px', bottom: 'auto', maxH }
                                                        : { left: r.left, width: r.width, top: 'auto', bottom: (vh - r.top + 4) + 'px', maxH };
                                                },
                                                openPanel() { this.place(); this.open = true; },
                                                choose(c) {
                                                    this.selectedName = c ? c.name : '';
                                                    this.search = this.selectedName;
                                                    this.open = false;
                                                    $wire.set('lines.{{ $i }}.component', c ? c.key : '');
                                                },
                                            }"
                                            @product-created.window="if ($event.detail.lineIndex === {{ $i }}) { selectedName = $event.detail.name; search = $event.detail.name; }"
                                            @click.outside="open = false; search = selectedName"
                                            @scroll.window.passive="if (open) place()"
                                            @resize.window="if (open) place()"
                                            class="relative">
                                            <input type="text" x-ref="input" x-model="search"
                                                @focus="openPanel()" @click="openPanel()"
                                                @keydown.escape.stop="open = false; search = selectedName"
                                                placeholder="{{ __('Search a product, condiment or ingredient…') }}"
                                                autocomplete="off" class="o-input">
                                            {{-- Fixed panel (not absolute): the lines table sits inside an
                                                 `overflow-hidden` wrapper that would clip an in-flow dropdown.
                                                 Coords are captured from the input on open. --}}
                                            <div x-show="open" x-cloak
                                                :style="`left:${coords.left}px; width:${coords.width}px; top:${coords.top}; bottom:${coords.bottom}; max-height:${coords.maxH}px;`"
                                                class="fixed z-50 overflow-auto rounded-lg border border-chrome-200 bg-white py-1 shadow-pop">
                                                <button type="button" @click="choose(null)"
                                                    class="flex w-full items-center px-3 py-1.5 text-start text-sm text-chrome-400 hover:bg-chrome-50">—</button>
                                                <template x-for="c in filterComponents(search)" :key="c.key">
                                                    <button type="button" @click="choose(c)"
                                                        class="flex w-full items-center justify-between gap-2 px-3 py-1.5 text-start text-sm text-chrome-700 hover:bg-primary-50">
                                                        <span x-text="c.name"></span>
                                                        <span x-show="c.type === 'product'"
                                                            class="shrink-0 rounded-full bg-chrome-100 px-2 py-0.5 text-[10px] font-medium text-chrome-600">{{ __('Product') }}</span>
                                                        <span x-show="c.type === 'condiment'"
                                                            class="shrink-0 rounded-full bg-primary-100 px-2 py-0.5 text-[10px] font-medium text-primary-700">{{ __('Condiment') }}</span>
                                                        <span x-show="c.type === 'ingredient'"
                                                            class="shrink-0 rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-medium text-emerald-700">{{ __('Ingredient') }}</span>
                                                    </button>
                                                </template>
                                                <template x-if="filterComponents(search).length === 0">
                                                    <p class="px-3 py-1.5 text-sm text-chrome-400">{{ __('No products found') }}</p>
                                                </template>
                                                @if ($canWrite || $canCreate)
                                                    <div class="mt-1 border-t border-chrome-100 pt-1">
                                                        <button type="button" @click="$wire.openProductModal({{ $i }}, search)"
                                                            class="flex w-full items-center gap-1 px-3 py-1.5 text-start text-sm font-medium text-primary-700 hover:bg-primary-50">
                                                            <svg class="size-4 shrink-0" viewBox="0 0 20 20" fill="currentColor"><path d="M10 5a1 1 0 0 1 1 1v3h3a1 1 0 1 1 0 2h-3v3a1 1 0 1 1-2 0v-3H6a1 1 0 1 1 0-2h3V6a1 1 0 0 1 1-1Z"/></svg>
                                                            <span x-show="search.trim() === ''">{{ __('New product') }}</span>
                                                            <span x-show="search.trim() !== ''" x-cloak>{{ __('Create') }} "<span x-text="search"></span>"</span>
                                                        </button>
                                                    </div>
                                                @endif
                                            </div>
                                        </div>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-end">
                                    <input type="number" step="any" min="0" wire:model.live.debounce.400ms="lines.{{ $i }}.quantity"
                                        @disabled($isConfirmed) class="o-input w-24 text-end">
                                </td>
                                <td class="px-3 py-2 text-end">
                                    <input type="number" step="any" min="0" wire:model.live.debounce.400ms="lines.{{ $i }}.unit_cost"
                                        @disabled($isConfirmed) class="o-input w-28 text-end">
                                    @if ($deliveryCost > 0 && ($landed[$i] ?? 0) > (float) ($line['unit_cost'] ?? 0))
                                        <p class="mt-1 whitespace-nowrap text-[11px] text-primary-600" title="{{ __('Unit cost + delivery share') }}">
                                            {{ __('Landed') }}: {{ Currencies::format($landed[$i]) }}
                                        </p>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-end font-medium text-chrome-700">
                                    {{ Currencies::format((float) ($line['quantity'] ?? 0) * (float) ($line['unit_cost'] ?? 0)) }}
                                </td>
                                @unless ($isConfirmed)
                                    <td class="px-3 py-2 text-end">
                                        <button type="button" wire:click="removeLine({{ $i }})"
                                            class="text-chrome-400 transition hover:text-red-600" title="{{ __('Remove') }}">
                                            <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8.75 1a1 1 0 0 0-.96.71L7.56 2.5H4a.75.75 0 0 0 0 1.5h12a.75.75 0 0 0 0-1.5h-3.56l-.23-.79A1 1 0 0 0 11.25 1h-2.5ZM5.5 6.5 6 16a2 2 0 0 0 2 1.9h4a2 2 0 0 0 2-1.9l.5-9.5h-9Z" clip-rule="evenodd"/></svg>
                                        </button>
                                    </td>
                                @endunless
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="bg-chrome-50">
                        <tr>
                            <td class="px-3 py-2 text-end text-chrome-500" colspan="3">{{ __('Goods subtotal') }}</td>
                            <td class="px-3 py-2 text-end text-chrome-700">{{ Currencies::format($goodsTotal) }}</td>
                            @unless ($isConfirmed) <td></td> @endunless
                        </tr>
                        <tr>
                            <td class="px-3 py-2 text-end text-chrome-500" colspan="3">{{ __('Delivery cost (we pay)') }}</td>
                            <td class="px-3 py-2 text-end">
                                @if ($isConfirmed)
                                    <span class="font-medium text-chrome-700">{{ Currencies::format($deliveryCost) }}</span>
                                @else
                                    <input type="number" step="any" min="0" wire:model.live.debounce.400ms="form.delivery_cost"
                                        class="o-input w-28 text-end" placeholder="0">
                                @endif
                            </td>
                            @unless ($isConfirmed) <td></td> @endunless
                        </tr>
                        <tr>
                            <td class="px-3 py-2 text-end font-semibold text-chrome-600" colspan="3">{{ __('Total') }}</td>
                            <td class="px-3 py-2 text-end font-bold text-chrome-900">{{ Currencies::format($total) }}</td>
                            @unless ($isConfirmed) <td></td> @endunless
                        </tr>
                        @if ($deliveryCost > 0)
                            <tr>
                                <td colspan="{{ $isConfirmed ? 4 : 5 }}" class="px-3 pb-2 text-end text-[11px] text-chrome-400">
                                    {{ __('Delivery is split across the items by value and folded into each item’s cost.') }}
                                </td>
                            </tr>
                        @endif
                    </tfoot>
                </table>
            </div>
        </div>

        <div class="border-t border-chrome-200 px-6 py-4">
            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Notes') }}</label>
            <textarea wire:model="form.notes" rows="2" @disabled($isConfirmed) class="o-input resize-none"></textarea>
        </div>
    </form>

    {{-- Inline "new vendor" modal. Lives OUTSIDE the bill <form> so its inputs
         and submit can't trip the outer form. Saving creates a Partner and
         selects it on the bill. --}}
    @if ($addingVendor)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4"
            x-data x-on:keydown.escape.window="$wire.closeVendorModal()"
            x-init="$nextTick(() => $refs.vendorName && $refs.vendorName.focus())">
            <div class="absolute inset-0 bg-chrome-900/40" wire:click="closeVendorModal"></div>
            <div class="relative w-full max-w-md rounded-xl bg-white p-5 shadow-pop ring-1 ring-chrome-900/5">
                <div class="mb-4 flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-chrome-800">{{ __('New vendor') }}</h3>
                    <button type="button" wire:click="closeVendorModal" class="text-chrome-400 transition hover:text-chrome-700" title="{{ __('Close') }}">
                        <svg class="size-5" viewBox="0 0 20 20" fill="currentColor"><path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z"/></svg>
                    </button>
                </div>
                <form wire:submit.prevent="saveVendor" class="space-y-3">
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Name') }} <span class="text-red-500">*</span></label>
                        <input type="text" wire:model="newVendor.name" x-ref="vendorName"
                            placeholder="{{ __('Vendor name') }}" class="o-input">
                        @error('newVendor.name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Phone') }}</label>
                            <input type="text" wire:model="newVendor.phone" class="o-input">
                            @error('newVendor.phone') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Email') }}</label>
                            <input type="email" wire:model="newVendor.email" class="o-input">
                            @error('newVendor.email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Location') }}</label>
                        <input type="text" wire:model="newVendor.location" placeholder="{{ __('City / area') }}" class="o-input">
                        @error('newVendor.location') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" wire:click="closeVendorModal"
                            class="rounded-md px-3 py-1.5 text-sm font-medium text-chrome-600 ring-1 ring-chrome-300 hover:bg-chrome-50">
                            {{ __('Cancel') }}
                        </button>
                        <button type="submit" class="o-btn-primary">{{ __('Add vendor') }}</button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    {{-- Inline "new product" modal — shared with the POS recipe editor. --}}
    @include('pos::partials.new-product-modal')
</div>
