<div class="mx-auto max-w-3xl p-4 sm:p-6">
    <x-form-breadcrumb :parent="__('Cars')" :parent-url="url('/app/rental/vehicle')" :current="$vehicle?->name ?? __('New car')" />

    <livewire:views.form-view
        :model="\Modules\Rental\Models\Vehicle::class"
        model-key="rental.vehicle"
        :record-id="$vehicle?->id"
        title="{{ $vehicle ? 'Edit car' : 'New car' }}"
        redirect-to="{{ url('/app/rental/vehicle') }}"
        :key="'rental-vehicle-form-'.($vehicle?->id ?? 'new')" />
</div>
