<div class="mx-auto max-w-7xl p-6">
    <div class="mb-4">
        <h1 class="text-xl font-bold text-chrome-900">Dynamic View Engine</h1>
        <p class="text-sm text-chrome-500">
            Both views below are rendered entirely from <code class="rounded bg-chrome-100 px-1">ir_ui_view</code>
            arch metadata for <code class="rounded bg-chrome-100 px-1">demo.ticket</code>.
        </p>
    </div>

    <div class="mb-4 inline-flex rounded-lg bg-chrome-200 p-1">
        <button wire:click="$set('tab', 'list')"
            class="o-btn {{ $tab === 'list' ? 'bg-white text-chrome-900 shadow-sm' : 'text-chrome-600' }}">
            List
        </button>
        <button wire:click="$set('tab', 'kanban')"
            class="o-btn {{ $tab === 'kanban' ? 'bg-white text-chrome-900 shadow-sm' : 'text-chrome-600' }}">
            Kanban
        </button>
    </div>

    @if ($tab === 'list')
        <livewire:views.list-view
            :model="\App\Models\Demo\DemoTicket::class"
            model-key="demo.ticket"
            title="Demo Tickets"
            :key="'list-demo-ticket'" />
    @else
        <livewire:views.kanban-view
            :model="\App\Models\Demo\DemoTicket::class"
            model-key="demo.ticket"
            title="Demo Tickets"
            :key="'kanban-demo-ticket'" />
    @endif
</div>
