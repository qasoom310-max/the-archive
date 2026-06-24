<div class="mx-auto max-w-3xl p-4 sm:p-6">
    <div class="mb-4 flex items-center gap-2 text-sm text-chrome-500">
        <a href="{{ url('/app/limousine/location') }}" wire:navigate class="hover:text-primary-700">{{ __('Locations') }}</a>
        <span>/</span>
        <span class="font-medium text-chrome-700">{{ $location?->name ?? __('New location') }}</span>
    </div>

    <livewire:views.form-view
        :model="\Modules\Limousine\Models\LimoLocation::class"
        model-key="limousine.location"
        :record-id="$location?->id"
        title="{{ $location ? 'Edit location' : 'New location' }}"
        redirect-to="{{ url('/app/limousine/location') }}"
        :key="'limo-location-form-'.($location?->id ?? 'new')" />
</div>
