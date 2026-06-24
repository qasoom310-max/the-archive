<div class="mx-auto max-w-7xl p-4 sm:p-6">
    <div class="mb-4 flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold text-chrome-900">{{ __('Branches') }}</h1>
            <p class="text-sm text-chrome-500">{{ __('Your rental locations.') }}</p>
        </div>
        <a href="{{ url('/app/rental/branch/new') }}" wire:navigate class="o-btn-primary">
            <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10 5a1 1 0 0 1 1 1v3h3a1 1 0 1 1 0 2h-3v3a1 1 0 1 1-2 0v-3H6a1 1 0 1 1 0-2h3V6a1 1 0 0 1 1-1Z"/></svg>
            {{ __('New') }}
        </a>
    </div>

    <livewire:views.list-view
        :model="\Modules\Rental\Models\Branch::class"
        model-key="rental.branch"
        title="Branches"
        :key="'rental-branches'" />
</div>
