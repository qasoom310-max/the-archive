<div class="mx-auto max-w-5xl p-4 sm:p-6">
    <x-form-breadcrumb :parent="__('Drivers')" :parent-url="url('/app/rental/driver')" :current="$driver?->name ?? __('New driver')" />

    <livewire:views.form-view
        :model="\Modules\Rental\Models\Driver::class"
        model-key="rental.driver"
        :record-id="$driver?->id"
        title="{{ $driver ? 'Edit driver' : 'New driver' }}"
        redirect-to="{{ url('/app/rental/driver') }}"
        :key="'rental-driver-form-'.($driver?->id ?? 'new')" />

    {{-- Both businesses, one list: the office asks what this person has been
         doing, not what they did in one app. --}}
    <x-driver-jobs :driver="$driver" />
</div>
