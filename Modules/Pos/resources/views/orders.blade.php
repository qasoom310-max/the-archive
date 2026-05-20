<div class="mx-auto max-w-7xl p-6">
    <div class="mb-4 flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold text-chrome-900">POS Orders</h1>
            <p class="text-sm text-chrome-500">Every order across all register sessions.</p>
        </div>
        <a href="{{ url('/app/pos') }}" wire:navigate class="o-btn-ghost">Back to sessions</a>
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
