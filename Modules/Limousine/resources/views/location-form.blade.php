<div class="mx-auto max-w-3xl p-4 sm:p-6">
    <x-form-breadcrumb :parent="__('Locations')" :parent-url="url('/app/limousine/location')" :current="$location?->name ?? __('New location')" />

    <livewire:views.form-view
        :model="\Modules\Limousine\Models\LimoLocation::class"
        model-key="limousine.location"
        :record-id="$location?->id"
        title="{{ $location ? 'Edit location' : 'New location' }}"
        redirect-to="{{ url('/app/limousine/location') }}"
        :key="'limo-location-form-'.($location?->id ?? 'new')" />
</div>
