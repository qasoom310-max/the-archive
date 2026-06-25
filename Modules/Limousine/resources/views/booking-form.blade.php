<div class="mx-auto max-w-4xl p-4 sm:p-6">
    <div class="mb-4 flex items-center gap-2 text-sm text-chrome-500">
        <a href="{{ url('/app/limousine/booking') }}" wire:navigate class="hover:text-primary-700">{{ __('Bookings') }}</a>
        <span>/</span>
        <span class="font-medium text-chrome-700">{{ $isEditing ? ($reference ?: __('Booking')) : __('New booking') }}</span>
    </div>

    @php
        $statusBadge = [
            'queue' => 'bg-amber-100 text-amber-700',
            'confirmed' => 'bg-sky-100 text-sky-700',
            'active' => 'bg-indigo-100 text-indigo-700',
            'completed' => 'bg-emerald-100 text-emerald-700',
            'cancelled' => 'bg-red-100 text-red-700',
        ][$status] ?? 'bg-chrome-200 text-chrome-700';
    @endphp

    @if ($isEditing)
        <div class="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/5">
            <div class="flex items-center gap-3">
                <span class="text-sm font-semibold text-chrome-800">{{ $reference }}</span>
                <span class="rounded px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide {{ $statusBadge }}">{{ __(ucfirst($status)) }}</span>
                <span class="rounded px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide {{ $payment_status === 'paid' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' }}">{{ __(ucfirst($payment_status)) }}</span>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @if ($status === 'queue')
                    <button wire:click="confirm" class="o-btn-primary text-sm">{{ __('Confirm') }}</button>
                @elseif ($status === 'confirmed')
                    <button wire:click="start" class="o-btn-primary text-sm">{{ __('Start trip') }}</button>
                @elseif ($status === 'active')
                    <button wire:click="complete" class="o-btn-primary text-sm">{{ __('Complete trip') }}</button>
                @endif

                @if ($status !== 'cancelled')
                    <button wire:click="createInvoice" class="o-btn-ghost text-sm">{{ __('Create invoice') }}</button>
                @endif

                @if ($payment_status === 'unpaid')
                    <button wire:click="markPaid" class="o-btn-ghost text-sm">{{ __('Mark paid') }}</button>
                @else
                    <button wire:click="markUnpaid" class="o-btn-ghost text-sm">{{ __('Mark unpaid') }}</button>
                @endif

                @if (! in_array($status, ['completed', 'cancelled'], true))
                    <button wire:click="cancelBooking" wire:confirm="{{ __('Cancel this booking?') }}" class="text-sm font-medium text-red-600 hover:underline">{{ __('Cancel') }}</button>
                @endif
            </div>
        </div>
    @endif

    <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5">
        <h2 class="mb-4 text-sm font-semibold text-chrome-800">{{ __('Trip details') }}</h2>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <div class="mb-1 flex items-center justify-between gap-2">
                    <label class="block text-sm font-medium text-chrome-700">{{ __('Customer') }} *</label>
                    <button type="button" wire:click="openCustomerModal"
                        class="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-xs font-medium text-primary-700 hover:bg-primary-50">
                        <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor"><path d="M10 5a1 1 0 0 1 1 1v3h3a1 1 0 1 1 0 2h-3v3a1 1 0 1 1-2 0v-3H6a1 1 0 1 1 0-2h3V6a1 1 0 0 1 1-1Z"/></svg>
                        {{ __('New customer') }}
                    </button>
                </div>
                <select wire:model="customer_id" class="o-input w-full">
                    <option value="">{{ __('— Select —') }}</option>
                    @foreach ($customers as $c)
                        <option value="{{ $c->id }}">{{ $c->name }}{{ $c->phone ? ' · ' . $c->phone : '' }}</option>
                    @endforeach
                </select>
                @error('customer_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Pick-up time') }} *</label>
                <input type="datetime-local" wire:model="pickup_at" class="o-input w-full">
                @error('pickup_at') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Pick-up location') }}</label>
                <select wire:model="pickup_location_id" class="o-input w-full">
                    <option value="">{{ __('— Select —') }}</option>
                    @foreach ($locations as $l)
                        <option value="{{ $l->id }}">{{ $l->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Dropoff location') }}</label>
                <select wire:model="dropoff_location_id" class="o-input w-full">
                    <option value="">{{ __('— Select —') }}</option>
                    @foreach ($locations as $l)
                        <option value="{{ $l->id }}">{{ $l->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Car type') }}</label>
                <select wire:model="car_type" class="o-input w-full">
                    @foreach ($carTypes as $opt)
                        <option value="{{ $opt['value'] }}">{{ __($opt['label']) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Passengers') }}</label>
                <input type="number" min="0" wire:model="passengers" class="o-input w-full">
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Driver') }}</label>
                <input type="text" wire:model="driver_name" class="o-input w-full">
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Fare (BHD)') }} *</label>
                <input type="number" step="0.001" min="0" wire:model="fare" class="o-input w-full">
                @error('fare') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div class="sm:col-span-2">
                <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Notes') }}</label>
                <textarea wire:model="notes" rows="2" class="o-input w-full"></textarea>
            </div>
        </div>

        <button wire:click="save" class="o-btn-primary mt-4 w-full justify-center">
            <span wire:loading.remove wire:target="save">{{ $isEditing ? __('Save booking') : __('Create booking') }}</span>
            <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
        </button>
    </div>

    @include('rental::partials.customer-modal')
</div>
