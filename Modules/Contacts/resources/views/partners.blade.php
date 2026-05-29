<div class="mx-auto max-w-7xl p-4 sm:p-6">
    <div class="mb-4 flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold text-chrome-900">Contacts</h1>
            <p class="text-sm text-chrome-500">Companies & individuals in your address book.</p>
        </div>
        <a href="{{ url('/app/contacts/partner/new') }}" wire:navigate class="o-btn-primary">
            <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10 5a1 1 0 0 1 1 1v3h3a1 1 0 1 1 0 2h-3v3a1 1 0 1 1-2 0v-3H6a1 1 0 1 1 0-2h3V6a1 1 0 0 1 1-1Z"/></svg>
            New
        </a>
    </div>

    <div class="mb-4 inline-flex rounded-lg bg-chrome-200 p-1">
        <button wire:click="$set('tab', 'kanban')"
            class="o-btn {{ $tab === 'kanban' ? 'bg-white text-chrome-900 shadow-sm' : 'text-chrome-600' }}">Kanban</button>
        <button wire:click="$set('tab', 'list')"
            class="o-btn {{ $tab === 'list' ? 'bg-white text-chrome-900 shadow-sm' : 'text-chrome-600' }}">List</button>
    </div>

    @if ($tab === 'list')
        <livewire:views.list-view
            :model="\Modules\Contacts\Models\Partner::class"
            model-key="contacts.partner"
            title="Contacts"
            :key="'partners-list'" />
    @else
        <livewire:views.kanban-view
            :model="\Modules\Contacts\Models\Partner::class"
            model-key="contacts.partner"
            title="Contacts by country"
            :key="'partners-kanban'" />
    @endif
</div>
