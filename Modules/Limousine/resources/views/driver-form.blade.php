<div class="mx-auto max-w-3xl p-4 sm:p-6">
    <x-form-breadcrumb :parent="__('Drivers')" :parent-url="url('/app/limousine/driver')" :current="$driver?->name ?? __('New driver')" />

    <livewire:views.form-view
        :model="\Modules\Limousine\Models\LimoDriver::class"
        model-key="limousine.driver"
        :record-id="$driver?->id"
        title="{{ $driver ? 'Edit driver' : 'New driver' }}"
        redirect-to="{{ url('/app/limousine/driver') }}"
        :key="'limo-driver-form-'.($driver?->id ?? 'new')" />
</div>
