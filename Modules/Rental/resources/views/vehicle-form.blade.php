<div class="mx-auto max-w-3xl p-4 sm:p-6">
    <x-form-breadcrumb :parent="__('Cars')" :parent-url="url('/app/rental/vehicle')" :current="$vehicle?->name ?? __('New car')" />

    {{-- Status panel — bring a car back from maintenance. --}}
    @if ($vehicle)
        @php
            $statusBadge = [
                'available' => 'bg-emerald-100 text-emerald-700',
                'rented' => 'bg-sky-100 text-sky-700',
                'maintenance' => 'bg-amber-100 text-amber-700',
                'reserved' => 'bg-violet-100 text-violet-700',
            ][$vehicle->status] ?? 'bg-chrome-200 text-chrome-700';
        @endphp
        <div class="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/[0.06]">
            <div class="flex items-center gap-2.5">
                <span class="text-sm font-semibold text-chrome-800">{{ $vehicle->displayName() }}</span>
                <span class="rounded-full px-2.5 py-0.5 text-[11px] font-semibold uppercase tracking-wide {{ $statusBadge }}">{{ __(ucfirst($vehicle->status)) }}</span>
            </div>
            @if ($vehicle->status === 'maintenance' && $canManage)
                <button wire:click="returnToService"
                    wire:confirm="{{ __('Return this car to the available fleet?') }}"
                    class="o-btn-primary text-sm">{{ __('Return to service') }}</button>
            @endif
        </div>
    @endif

    <livewire:views.form-view
        :model="\Modules\Rental\Models\Vehicle::class"
        model-key="rental.vehicle"
        :record-id="$vehicle?->id"
        title="{{ $vehicle ? 'Edit car' : 'New car' }}"
        redirect-to="{{ url('/app/rental/vehicle') }}"
        :key="'rental-vehicle-form-'.($vehicle?->id ?? 'new')" />
</div>
