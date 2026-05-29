<div class="mx-auto max-w-7xl p-4 sm:p-6">
    <div class="mb-4 flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold text-chrome-900">POS Orders</h1>
            <p class="text-sm text-chrome-500">Every order across all register sessions.</p>
        </div>

        <div class="flex items-center gap-2">
            <a href="{{ url('/app/pos') }}" wire:navigate class="o-btn-ghost">Back to sessions</a>

            {{-- Odoo-style action menu: 3-dot button → sectioned dropdown.
                 View → straight links to either list. Reporting → the
                 dedicated reporting page (pre-filtered to today). Alpine
                 handles open/close + click-outside; the menu items are
                 plain anchors so wire:navigate keeps SPA navigation alive. --}}
            <div x-data="{ open: false }" @click.outside="open = false" class="relative">
                <button type="button" @click="open = !open"
                    aria-label="Order actions"
                    class="flex size-9 items-center justify-center rounded-lg border border-chrome-300 bg-white text-chrome-600 hover:bg-chrome-100">
                    {{-- Heroicons mini ellipsis-vertical --}}
                    <svg class="size-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M12 6.75a.75.75 0 1 1 0-1.5.75.75 0 0 1 0 1.5Zm0 6a.75.75 0 1 1 0-1.5.75.75 0 0 1 0 1.5Zm0 6a.75.75 0 1 1 0-1.5.75.75 0 0 1 0 1.5Z" />
                    </svg>
                </button>

                <div x-show="open" x-cloak x-transition.opacity
                    class="absolute right-0 z-30 mt-1 w-56 origin-top-right rounded-lg border border-chrome-200 bg-white py-2 shadow-pop">
                    <p class="px-3 pb-1 text-xs font-semibold uppercase tracking-wide text-chrome-400">View</p>
                    <a href="{{ url('/app/pos/order') }}" wire:navigate
                        class="block px-3 py-1.5 text-sm text-chrome-700 hover:bg-chrome-100">Orders</a>
                    <a href="{{ url('/app/pos') }}" wire:navigate
                        class="block px-3 py-1.5 text-sm text-chrome-700 hover:bg-chrome-100">Sessions</a>

                    <div class="my-1 border-t border-chrome-100"></div>

                    <p class="px-3 pb-1 text-xs font-semibold uppercase tracking-wide text-chrome-400">Reporting</p>
                    <a href="{{ url('/app/pos/reporting') }}" wire:navigate
                        class="block px-3 py-1.5 text-sm text-chrome-700 hover:bg-chrome-100">Orders</a>
                </div>
            </div>
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
            :model="\Modules\Pos\Models\PosOrder::class"
            model-key="pos.order"
            title="POS Orders"
            :key="'pos-orders-kanban'" />
    @else
        <livewire:views.list-view
            :model="\Modules\Pos\Models\PosOrder::class"
            model-key="pos.order"
            title="POS Orders"
            :key="'pos-orders-list'" />
    @endif
</div>
