<div class="mx-auto max-w-7xl p-4 sm:p-6">
    <div class="mb-4 flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold text-chrome-900">POS Products</h1>
            <p class="text-sm text-chrome-500">Sellable products available on the register.</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ url('/app/pos/product/import/template') }}" class="o-btn o-btn-ghost flex items-center gap-1.5 text-sm" download>
                {{-- Heroicons outline arrow-down — replaces the `⬇` Unicode glyph,
                     which Windows/Chrome render as a coloured emoji. Inline SVG
                     inherits text colour so it matches the rest of the toolbar. --}}
                <svg class="size-4" viewBox="0 0 20 20" fill="none" stroke="currentColor"
                    stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M10 3.5v13m0 0-4.5-4.5M10 16.5l4.5-4.5"/>
                </svg>
                Template
            </a>
            {{-- Data-transfer dropdown: Import (creates/updates from file)
                 + Export (downloads everything as CSV). Sibling actions
                 because they're the inverse of each other; combining them
                 saves a top-level button slot. Import requires Create; Export
                 only requires Read, so cashiers see Export but not Import. --}}
            <div x-data="{ open: false }" @click.outside="open = false" class="relative">
                <button type="button" @click="open = !open"
                    class="o-btn o-btn-ghost flex items-center gap-1.5 text-sm">
                    <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10 3a1 1 0 0 1 1 1v6.59l1.3-1.3a1 1 0 1 1 1.4 1.42l-3 3a1 1 0 0 1-1.4 0l-3-3a1 1 0 1 1 1.4-1.42L9 10.6V4a1 1 0 0 1 1-1ZM4 14a1 1 0 0 1 1 1v1h10v-1a1 1 0 1 1 2 0v2a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-2a1 1 0 0 1 1-1Z"/></svg>
                    Import
                    <svg class="size-3 opacity-70" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="m6 9 6 6 6-6" />
                    </svg>
                </button>

                <div x-show="open" x-cloak x-transition.opacity
                    class="absolute right-0 z-30 mt-1 w-52 origin-top-right rounded-lg border border-chrome-200 bg-white py-2 shadow-pop">
                    @if ($canCreate)
                        <a href="{{ url('/app/pos/product/import') }}" wire:navigate
                            class="flex items-center gap-2 px-3 py-2 text-sm text-chrome-700 hover:bg-chrome-100">
                            {{-- Up-arrow into tray = Import --}}
                            <svg class="size-4 text-chrome-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" />
                            </svg>
                            Import from file
                        </a>
                    @endif
                    <a href="{{ url('/app/pos/product/export') }}"
                        class="flex items-center gap-2 px-3 py-2 text-sm text-chrome-700 hover:bg-chrome-100">
                        {{-- Down-arrow out of tray = Export --}}
                        <svg class="size-4 text-chrome-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5" />
                        </svg>
                        Export all products
                    </a>
                </div>
            </div>

            @if ($canCreate)
                <a href="{{ url('/app/pos/product/new') }}" wire:navigate class="o-btn-primary">
                    <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10 5a1 1 0 0 1 1 1v3h3a1 1 0 1 1 0 2h-3v3a1 1 0 1 1-2 0v-3H6a1 1 0 1 1 0-2h3V6a1 1 0 0 1 1-1Z"/></svg>
                    New
                </a>
            @endif
        </div>
    </div>

    <div class="mb-4 inline-flex rounded-lg bg-chrome-200 p-1">
        <button wire:click="$set('tab', 'list')"
            class="o-btn {{ $tab === 'list' ? 'bg-white text-chrome-900 shadow-sm' : 'text-chrome-600' }}">List</button>
        <button wire:click="$set('tab', 'kanban')"
            class="o-btn {{ $tab === 'kanban' ? 'bg-white text-chrome-900 shadow-sm' : 'text-chrome-600' }}">Kanban</button>
    </div>

    @if ($tab === 'kanban')
        <livewire:views.kanban-view
            :model="\Modules\Pos\Models\PosProduct::class"
            model-key="pos.product"
            title="POS Products"
            :key="'pos-products-kanban'" />
    @else
        <livewire:views.list-view
            :model="\Modules\Pos\Models\PosProduct::class"
            model-key="pos.product"
            title="POS Products"
            :key="'pos-products-list'" />
    @endif
</div>
