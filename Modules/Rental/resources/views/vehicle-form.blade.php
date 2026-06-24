<div class="mx-auto max-w-3xl p-4 sm:p-6">
    <div class="mb-4 flex items-center gap-2 text-sm text-chrome-500">
        <a href="{{ url('/app/rental/vehicle') }}" wire:navigate class="hover:text-primary-700">{{ __('Vehicles') }}</a>
        <span>/</span>
        <span class="font-medium text-chrome-700">{{ $vehicle?->name ?? __('New vehicle') }}</span>
    </div>

    <livewire:views.form-view
        :model="\Modules\Rental\Models\Vehicle::class"
        model-key="rental.vehicle"
        :record-id="$vehicle?->id"
        title="{{ $vehicle ? 'Edit vehicle' : 'New vehicle' }}"
        redirect-to="{{ url('/app/rental/vehicle') }}"
        :key="'rental-vehicle-form-'.($vehicle?->id ?? 'new')" />
</div>
