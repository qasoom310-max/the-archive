<div class="mx-auto max-w-7xl p-6">
    <div class="mb-4 flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold text-chrome-900">POS Products</h1>
            <p class="text-sm text-chrome-500">Sellable products available on the register.</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ url('/app/pos/product/import/template') }}" class="o-btn o-btn-ghost text-sm" download>
                ⬇ Template
            </a>
            @if ($canCreate)
                <a href="{{ url('/app/pos/product/import') }}" wire:navigate class="o-btn o-btn-ghost text-sm">
                    <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10 3a1 1 0 0 1 1 1v6.59l1.3-1.3a1 1 0 1 1 1.4 1.42l-3 3a1 1 0 0 1-1.4 0l-3-3a1 1 0 1 1 1.4-1.42L9 10.6V4a1 1 0 0 1 1-1ZM4 14a1 1 0 0 1 1 1v1h10v-1a1 1 0 1 1 2 0v2a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-2a1 1 0 0 1 1-1Z"/></svg>
                    Import
                </a>
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
