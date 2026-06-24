<div class="mx-auto max-w-3xl p-4 sm:p-6">
    <div class="mb-4 flex items-center gap-2 text-sm text-chrome-500">
        <a href="{{ url('/app/rental/driver') }}" wire:navigate class="hover:text-primary-700">{{ __('Drivers') }}</a>
        <span>/</span>
        <span class="font-medium text-chrome-700">{{ $driver?->name ?? __('New driver') }}</span>
    </div>

    <livewire:views.form-view
        :model="\Modules\Rental\Models\Driver::class"
        model-key="rental.driver"
        :record-id="$driver?->id"
        title="{{ $driver ? 'Edit driver' : 'New driver' }}"
        redirect-to="{{ url('/app/rental/driver') }}"
        :key="'rental-driver-form-'.($driver?->id ?? 'new')" />
</div>
