<div class="mx-auto max-w-7xl p-4 sm:p-6">
    <x-page-header :title="__('Drivers')" :subtitle="__('Drivers for with-driver rentals.')" icon="user" accent="primary">
        <x-slot:actions>
            <a href="{{ url('/app/rental/driver/new') }}" wire:navigate class="o-btn-primary">
                <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10 5a1 1 0 0 1 1 1v3h3a1 1 0 1 1 0 2h-3v3a1 1 0 1 1-2 0v-3H6a1 1 0 1 1 0-2h3V6a1 1 0 0 1 1-1Z"/></svg>
                {{ __('New') }}
            </a>
        </x-slot:actions>
    </x-page-header>

    <livewire:views.list-view
        :model="\Modules\Rental\Models\Driver::class"
        model-key="rental.driver"
        title="Drivers"
        :key="'rental-drivers'" />
</div>
