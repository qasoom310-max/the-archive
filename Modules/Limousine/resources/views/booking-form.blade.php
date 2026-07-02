<div class="mx-auto max-w-4xl p-4 sm:p-6">
    <x-form-breadcrumb :parent="__('Bookings')" :parent-url="url('/app/limousine/booking')" :current="$isEditing ? ($reference ?: __('Booking')) : __('New booking')" />

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
        <div class="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/[0.06]">
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

    @php
        $lbl = 'mb-1 block text-sm font-medium text-chrome-700';
        $sec = 'mt-6 mb-3 border-t border-dashed border-chrome-200 pt-4 text-xs font-bold uppercase tracking-wider text-chrome-400 first:mt-0 first:border-0 first:pt-0';
        $netPreview = max(0, (float) $amount - (float) $discount);
    @endphp
    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/[0.06] sm:p-6">

        {{-- ── Booking ── --}}
        <div class="{{ $sec }}">{{ __('Booking') }}</div>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            @if ($isEditing)
                <div>
                    <label class="{{ $lbl }}">{{ __('Booking number') }}</label>
                    <input type="text" value="{{ $reference }}" disabled class="o-input w-full bg-chrome-50 text-chrome-500">
                </div>
            @endif
            <div>
                <label class="{{ $lbl }}">{{ __('Booking type') }}</label>
                <select wire:model="booking_type" class="o-input w-full">
                    <option value="">{{ __('— Select —') }}</option>
                    @foreach ($bookingTypes as $opt)
                        <option value="{{ $opt['value'] }}">{{ __($opt['label']) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="{{ $lbl }}">{{ __('Booking from') }} *</label>
                <input type="datetime-local" wire:model="pickup_at" class="o-input w-full">
                @error('pickup_at') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="{{ $lbl }}">{{ __('Booking to') }}</label>
                <input type="datetime-local" wire:model="booking_to" class="o-input w-full">
                @error('booking_to') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
        </div>

        {{-- ── Customer ── --}}
        <div class="{{ $sec }}">{{ __('Customer') }}</div>
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
                <label class="{{ $lbl }}">{{ __('Contact person') }}</label>
                <input type="text" wire:model="contact_person" class="o-input w-full">
            </div>
            <div class="sm:col-span-2">
                <label class="{{ $lbl }}">{{ __('Company reference') }}</label>
                <input type="text" wire:model="company_reference" class="o-input w-full">
            </div>
        </div>

        {{-- ── Passenger ── --}}
        <div class="{{ $sec }}">{{ __('Passenger') }}</div>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <label class="{{ $lbl }}">{{ __('PAX name') }} *</label>
                <input type="text" wire:model="pax_name" class="o-input w-full">
                @error('pax_name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="{{ $lbl }}">{{ __('PAX contact') }}</label>
                <input type="text" wire:model="pax_contact" class="o-input w-full">
            </div>
            <div>
                <label class="{{ $lbl }}">{{ __('Flight number') }}</label>
                <input type="text" wire:model="flight_number" class="o-input w-full">
            </div>
            <div>
                <label class="{{ $lbl }}">{{ __('Email') }}</label>
                <input type="email" wire:model="email" class="o-input w-full">
                @error('email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
        </div>

        {{-- ── Route ── --}}
        <div class="{{ $sec }}">{{ __('Route') }}</div>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <label class="{{ $lbl }}">{{ __('Pick-up location') }}</label>
                <select wire:model="pickup_location_id" class="o-input w-full">
                    <option value="">{{ __('— Select —') }}</option>
                    @foreach ($locations as $l)
                        <option value="{{ $l->id }}">{{ $l->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="{{ $lbl }}">{{ __('Pick-up address') }}</label>
                <textarea wire:model="pickup_address" rows="2" class="o-input w-full"></textarea>
            </div>
            <div>
                <label class="{{ $lbl }}">{{ __('Drop-off location') }}</label>
                <select wire:model="dropoff_location_id" class="o-input w-full">
                    <option value="">{{ __('— Select —') }}</option>
                    @foreach ($locations as $l)
                        <option value="{{ $l->id }}">{{ $l->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="{{ $lbl }}">{{ __('Drop-off address') }}</label>
                <textarea wire:model="dropoff_address" rows="2" class="o-input w-full"></textarea>
            </div>
        </div>

        {{-- ── Charges ── --}}
        <div class="{{ $sec }}">{{ __('Charges') }}</div>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <label class="{{ $lbl }}">{{ __('Amount (BHD)') }} *</label>
                <input type="number" step="0.001" min="0" wire:model.live="amount" class="o-input w-full">
                @error('amount') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="{{ $lbl }}">{{ __('Rate type') }} *</label>
                <select wire:model="rate_type" class="o-input w-full">
                    <option value="">{{ __('— Select —') }}</option>
                    @foreach ($rateTypes as $opt)
                        <option value="{{ $opt['value'] }}">{{ __($opt['label']) }}</option>
                    @endforeach
                </select>
                @error('rate_type') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="{{ $lbl }}">{{ __('Discount (BHD)') }}</label>
                <input type="number" step="0.001" min="0" wire:model.live="discount" class="o-input w-full">
            </div>
            <div>
                <label class="{{ $lbl }}">{{ __('Net amount (BHD)') }}</label>
                <input type="text" value="{{ \App\Erp\Views\ValueFormat::money($netPreview) }}" disabled class="o-input w-full bg-chrome-50 font-semibold text-chrome-800">
            </div>
            <div>
                <label class="{{ $lbl }}">{{ __('Advance (BHD)') }}</label>
                <input type="number" step="0.001" min="0" wire:model="advance" class="o-input w-full">
            </div>
            <div>
                <label class="{{ $lbl }}">{{ __('Payment method') }} *</label>
                <div class="flex flex-wrap gap-2">
                    @foreach ($paymentMethods as $pm)
                        <label class="cursor-pointer" wire:key="pm-{{ $pm['value'] }}">
                            <input type="radio" wire:model.live="payment_method" value="{{ $pm['value'] }}" class="peer sr-only">
                            <span class="block rounded-lg border border-chrome-200 px-3 py-1.5 text-sm text-chrome-600 transition hover:bg-chrome-50 peer-checked:border-primary-500 peer-checked:bg-primary-50 peer-checked:font-medium peer-checked:text-primary-700">{{ __($pm['label']) }}</span>
                        </label>
                    @endforeach
                </div>
                @error('payment_method') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
        </div>

        {{-- ── Vehicle ── --}}
        <div class="{{ $sec }}">{{ __('Vehicle') }}</div>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <label class="{{ $lbl }}">{{ __('Car type') }}</label>
                <select wire:model="car_type" class="o-input w-full">
                    @foreach ($carTypes as $opt)
                        <option value="{{ $opt['value'] }}">{{ __($opt['label']) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="{{ $lbl }}">{{ __('Number of cars') }}</label>
                <input type="number" min="1" wire:model="num_cars" class="o-input w-full">
                @error('num_cars') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="{{ $lbl }}">{{ __('Car details') }} *</label>
                <input type="text" wire:model="car_details" class="o-input w-full" placeholder="{{ __('e.g. Lexus ES · white · plate 12345') }}">
                @error('car_details') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="{{ $lbl }}">{{ __('Driver') }}</label>
                <input type="text" wire:model="driver_name" class="o-input w-full">
            </div>
            <div>
                <label class="{{ $lbl }}">{{ __('Passengers') }}</label>
                <input type="number" min="0" wire:model="passengers" class="o-input w-full">
            </div>
        </div>

        {{-- ── Sign-off ── --}}
        <div class="{{ $sec }}">{{ __('Sign-off') }}</div>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <label class="{{ $lbl }}">{{ __('Requested by') }} *</label>
                <input type="text" wire:model="requested_by" class="o-input w-full">
                @error('requested_by') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="{{ $lbl }}">{{ __('Prepared by') }} *</label>
                <input type="text" wire:model="prepared_by" class="o-input w-full">
                @error('prepared_by') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div class="sm:col-span-2">
                <label class="{{ $lbl }}">{{ __('Comments') }}</label>
                <textarea wire:model="notes" rows="2" class="o-input w-full"></textarea>
            </div>
        </div>

        <button wire:click="save" class="o-btn-primary mt-6 w-full justify-center">
            <span wire:loading.remove wire:target="save">{{ $isEditing ? __('Save booking') : __('Add record') }}</span>
            <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
        </button>
    </div>

    @include('rental::partials.customer-modal')
</div>
