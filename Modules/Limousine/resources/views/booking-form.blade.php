<div class="mx-auto max-w-6xl p-4 sm:p-6">
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
        use App\Erp\Views\ValueFormat;
        $lbl = 'mb-1 block text-sm font-medium text-chrome-700';
        $sectionCls = 'rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5 sm:p-6';

        $netPreview = max(0, (float) $amount - (float) $discount);
        $advNum = (float) ($advance === '' ? '0' : $advance);
        $balancePreview = max(0, $netPreview - $advNum);

        $selCustomer = $customers->firstWhere('id', (int) $customer_id);
        $selPickup = $locations->firstWhere('id', (int) $pickup_location_id);
        $selDropoff = $locations->firstWhere('id', (int) $dropoff_location_id);
        $carLabel = collect($carTypes)->firstWhere('value', $car_type)['label'] ?? null;
        $payLabel = collect($paymentMethods)->firstWhere('value', $payment_method)['label'] ?? null;
        $whenLabel = $pickup_at !== '' ? \Illuminate\Support\Carbon::parse($pickup_at)->isoFormat('MMM D · h:mm A') : null;

        // Icon paths keyed for the section headers.
        $icons = [
            'booking' => '<path fill-rule="evenodd" d="M5.75 2a.75.75 0 0 1 .75.75V4h7V2.75a.75.75 0 0 1 1.5 0V4h.25A2.75 2.75 0 0 1 18 6.75v8.5A2.75 2.75 0 0 1 15.25 18H4.75A2.75 2.75 0 0 1 2 15.25v-8.5A2.75 2.75 0 0 1 4.75 4H5V2.75A.75.75 0 0 1 5.75 2Zm-1 5.5c-.69 0-1.25.56-1.25 1.25v6.5c0 .69.56 1.25 1.25 1.25h10.5c.69 0 1.25-.56 1.25-1.25v-6.5c0-.69-.56-1.25-1.25-1.25H4.75Z" clip-rule="evenodd"/>',
            'customer' => '<path d="M10 8a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM3.465 14.493a1.23 1.23 0 0 0 .41 1.412A9.957 9.957 0 0 0 10 18c2.31 0 4.438-.784 6.131-2.1.43-.333.604-.903.408-1.41a7.002 7.002 0 0 0-13.074.003Z"/>',
            'passenger' => '<path fill-rule="evenodd" d="M1 6a3 3 0 0 1 3-3h12a3 3 0 0 1 3 3v8a3 3 0 0 1-3 3H4a3 3 0 0 1-3-3V6Zm4.5 2.5a1.5 1.5 0 1 1 3 0 1.5 1.5 0 0 1-3 0Zm1.5 3a3 3 0 0 0-2.599 1.5H9.6A3 3 0 0 0 7 11.5Zm5.25-2.25a.75.75 0 0 0 0 1.5h2.5a.75.75 0 0 0 0-1.5h-2.5Zm0 3a.75.75 0 0 0 0 1.5h2.5a.75.75 0 0 0 0-1.5h-2.5Z" clip-rule="evenodd"/>',
            'route' => '<path fill-rule="evenodd" d="M9.69 18.933l.003.001C9.89 19.02 10 19 10 19s.11.02.308-.066l.002-.001.006-.003.018-.008a5.741 5.741 0 0 0 .281-.14c.186-.096.446-.24.757-.433.62-.384 1.445-.966 2.274-1.765C15.302 14.988 17 12.493 17 9A7 7 0 1 0 3 9c0 3.492 1.698 5.988 3.355 7.584a13.731 13.731 0 0 0 2.273 1.765 11.842 11.842 0 0 0 .976.544l.062.029.018.008.006.003ZM10 11.25a2.25 2.25 0 1 0 0-4.5 2.25 2.25 0 0 0 0 4.5Z" clip-rule="evenodd"/>',
            'charges' => '<path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm.75-11.25a.75.75 0 0 0-1.5 0v.316a3.78 3.78 0 0 0-1.653.713c-.426.33-.744.74-.881 1.209-.145.497-.115 1.028.104 1.499.219.47.638.856 1.256 1.114.4.167.86.275 1.324.35v1.626a2.16 2.16 0 0 1-.929-.284c-.242-.16-.362-.334-.394-.482a.75.75 0 1 0-1.466.318c.155.716.657 1.269 1.259 1.618a3.78 3.78 0 0 0 1.53.494v.316a.75.75 0 0 0 1.5 0v-.316a3.78 3.78 0 0 0 1.653-.713c.426-.33.744-.74.881-1.209.145-.497.115-1.028-.104-1.499-.219-.47-.638-.856-1.256-1.114a5.293 5.293 0 0 0-1.324-.35V7.5c.331.036.65.14.929.284.242.16.362.334.394.482a.75.75 0 0 0 1.466-.318c-.155-.716-.657-1.269-1.259-1.618a3.78 3.78 0 0 0-1.53-.494V6.75Z" clip-rule="evenodd"/>',
            'vehicle' => '<path d="M3 9.5 4.2 6.6A2 2 0 0 1 6 5.5h8a2 2 0 0 1 1.8 1.1L17 9.5a2 2 0 0 1 1 1.7V13a1 1 0 0 1-1 1h-1a2 2 0 1 1-4 0H8a2 2 0 1 1-4 0H3a1 1 0 0 1-1-1v-1.8a2 2 0 0 1 1-1.7Z"/><circle cx="6.5" cy="14" r="1.5"/><circle cx="13.5" cy="14" r="1.5"/>',
            'signoff' => '<path fill-rule="evenodd" d="M4.5 2A1.5 1.5 0 0 0 3 3.5v13A1.5 1.5 0 0 0 4.5 18h11a1.5 1.5 0 0 0 1.5-1.5V7.621a1.5 1.5 0 0 0-.44-1.06l-3.62-3.622A1.5 1.5 0 0 0 11.378 2H4.5Zm2.25 8.5a.75.75 0 0 0 0 1.5h6.5a.75.75 0 0 0 0-1.5h-6.5Zm0 3a.75.75 0 0 0 0 1.5h6.5a.75.75 0 0 0 0-1.5h-6.5Z" clip-rule="evenodd"/>',
        ];
    @endphp

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        {{-- ─────────────── Form ─────────────── --}}
        <div class="space-y-5 lg:col-span-2">

            {{-- Booking --}}
            <section class="{{ $sectionCls }}">
                <header class="mb-4 flex items-center gap-2.5">
                    <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary-50 text-primary-600"><svg class="size-4" viewBox="0 0 20 20" fill="currentColor">{!! $icons['booking'] !!}</svg></span>
                    <div><h2 class="text-sm font-semibold text-chrome-800">{{ __('Booking') }}</h2><p class="text-xs text-chrome-400">{{ __('Type and the trip window.') }}</p></div>
                </header>
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
                            @foreach ($bookingTypes as $opt)<option value="{{ $opt['value'] }}">{{ __($opt['label']) }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label class="{{ $lbl }}">{{ __('Booking from') }} *</label>
                        <input type="datetime-local" wire:model.live="pickup_at" class="o-input w-full">
                        @error('pickup_at') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="{{ $lbl }}">{{ __('Booking to') }}</label>
                        <input type="datetime-local" wire:model="booking_to" class="o-input w-full">
                        @error('booking_to') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>
            </section>

            {{-- Customer --}}
            <section class="{{ $sectionCls }}">
                <header class="mb-4 flex items-center gap-2.5">
                    <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary-50 text-primary-600"><svg class="size-4" viewBox="0 0 20 20" fill="currentColor">{!! $icons['customer'] !!}</svg></span>
                    <div><h2 class="text-sm font-semibold text-chrome-800">{{ __('Customer') }}</h2><p class="text-xs text-chrome-400">{{ __('Who the booking is for.') }}</p></div>
                </header>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <div class="mb-1 flex items-center justify-between gap-2">
                            <label class="block text-sm font-medium text-chrome-700">{{ __('Customer') }} *</label>
                            <button type="button" wire:click="openCustomerModal" class="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-xs font-medium text-primary-700 hover:bg-primary-50">
                                <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor"><path d="M10 5a1 1 0 0 1 1 1v3h3a1 1 0 1 1 0 2h-3v3a1 1 0 1 1-2 0v-3H6a1 1 0 1 1 0-2h3V6a1 1 0 0 1 1-1Z"/></svg>
                                {{ __('New customer') }}
                            </button>
                        </div>
                        <select wire:model.live="customer_id" class="o-input w-full">
                            <option value="">{{ __('— Select —') }}</option>
                            @foreach ($customers as $c)<option value="{{ $c->id }}">{{ $c->name }}{{ $c->phone ? ' · ' . $c->phone : '' }}</option>@endforeach
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
            </section>

            {{-- Passenger --}}
            <section class="{{ $sectionCls }}">
                <header class="mb-4 flex items-center gap-2.5">
                    <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary-50 text-primary-600"><svg class="size-4" viewBox="0 0 20 20" fill="currentColor">{!! $icons['passenger'] !!}</svg></span>
                    <div><h2 class="text-sm font-semibold text-chrome-800">{{ __('Passenger') }}</h2><p class="text-xs text-chrome-400">{{ __('Traveller and flight details.') }}</p></div>
                </header>
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
            </section>

            {{-- Route --}}
            <section class="{{ $sectionCls }}">
                <header class="mb-4 flex items-center gap-2.5">
                    <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary-50 text-primary-600"><svg class="size-4" viewBox="0 0 20 20" fill="currentColor">{!! $icons['route'] !!}</svg></span>
                    <div><h2 class="text-sm font-semibold text-chrome-800">{{ __('Route') }}</h2><p class="text-xs text-chrome-400">{{ __('Pick-up and drop-off.') }}</p></div>
                </header>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label class="{{ $lbl }}">{{ __('Pick-up location') }}</label>
                        <select wire:model.live="pickup_location_id" class="o-input w-full">
                            <option value="">{{ __('— Select —') }}</option>
                            @foreach ($locations as $l)<option value="{{ $l->id }}">{{ $l->name }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label class="{{ $lbl }}">{{ __('Pick-up address') }}</label>
                        <textarea wire:model="pickup_address" rows="2" class="o-input w-full"></textarea>
                    </div>
                    <div>
                        <label class="{{ $lbl }}">{{ __('Drop-off location') }}</label>
                        <select wire:model.live="dropoff_location_id" class="o-input w-full">
                            <option value="">{{ __('— Select —') }}</option>
                            @foreach ($locations as $l)<option value="{{ $l->id }}">{{ $l->name }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label class="{{ $lbl }}">{{ __('Drop-off address') }}</label>
                        <textarea wire:model="dropoff_address" rows="2" class="o-input w-full"></textarea>
                    </div>
                </div>
            </section>

            {{-- Charges --}}
            <section class="{{ $sectionCls }}">
                <header class="mb-4 flex items-center gap-2.5">
                    <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary-50 text-primary-600"><svg class="size-4" viewBox="0 0 20 20" fill="currentColor">{!! $icons['charges'] !!}</svg></span>
                    <div><h2 class="text-sm font-semibold text-chrome-800">{{ __('Charges') }}</h2><p class="text-xs text-chrome-400">{{ __('Amount, discount, advance and method.') }}</p></div>
                </header>
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
                            @foreach ($rateTypes as $opt)<option value="{{ $opt['value'] }}">{{ __($opt['label']) }}</option>@endforeach
                        </select>
                        @error('rate_type') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="{{ $lbl }}">{{ __('Discount (BHD)') }}</label>
                        <input type="number" step="0.001" min="0" wire:model.live="discount" class="o-input w-full">
                    </div>
                    <div>
                        <label class="{{ $lbl }}">{{ __('Advance (BHD)') }}</label>
                        <input type="number" step="0.001" min="0" wire:model.live="advance" class="o-input w-full">
                    </div>
                    <div class="sm:col-span-2">
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
            </section>

            {{-- Vehicle --}}
            <section class="{{ $sectionCls }}">
                <header class="mb-4 flex items-center gap-2.5">
                    <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary-50 text-primary-600"><svg class="size-4" viewBox="0 0 20 20" fill="currentColor">{!! $icons['vehicle'] !!}</svg></span>
                    <div><h2 class="text-sm font-semibold text-chrome-800">{{ __('Vehicle') }}</h2><p class="text-xs text-chrome-400">{{ __('Car, count and driver.') }}</p></div>
                </header>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label class="{{ $lbl }}">{{ __('Car type') }}</label>
                        <select wire:model.live="car_type" class="o-input w-full">
                            @foreach ($carTypes as $opt)<option value="{{ $opt['value'] }}">{{ __($opt['label']) }}</option>@endforeach
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
            </section>

            {{-- Sign-off --}}
            <section class="{{ $sectionCls }}">
                <header class="mb-4 flex items-center gap-2.5">
                    <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary-50 text-primary-600"><svg class="size-4" viewBox="0 0 20 20" fill="currentColor">{!! $icons['signoff'] !!}</svg></span>
                    <div><h2 class="text-sm font-semibold text-chrome-800">{{ __('Sign-off') }}</h2><p class="text-xs text-chrome-400">{{ __('Who requested and prepared it.') }}</p></div>
                </header>
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
            </section>
        </div>

        {{-- ─────────────── Live summary ─────────────── --}}
        <div class="lg:col-span-1">
            <div class="space-y-4 lg:sticky lg:top-6">
                <div class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-chrome-900/5">
                    <div class="flex items-center justify-between border-b border-chrome-100 bg-chrome-50/60 px-5 py-3">
                        <h2 class="text-sm font-semibold text-chrome-800">{{ __('Summary') }}</h2>
                        @if ($whenLabel)<span class="text-xs text-chrome-400">{{ $whenLabel }}</span>@endif
                    </div>
                    <div class="p-5">
                        {{-- Trip recap --}}
                        <div class="mb-4 space-y-2 text-sm">
                            <div class="flex items-start gap-2">
                                <svg class="mt-0.5 size-4 shrink-0 text-chrome-300" viewBox="0 0 20 20" fill="currentColor">{!! $icons['customer'] !!}</svg>
                                <span class="text-chrome-700">{{ $selCustomer?->name ?? __('No customer yet') }}</span>
                            </div>
                            <div class="flex items-start gap-2">
                                <svg class="mt-0.5 size-4 shrink-0 text-chrome-300" viewBox="0 0 20 20" fill="currentColor">{!! $icons['route'] !!}</svg>
                                <span class="text-chrome-600">{{ $selPickup?->name ?? __('Pick-up') }} <span class="text-chrome-300">→</span> {{ $selDropoff?->name ?? __('Drop-off') }}</span>
                            </div>
                            <div class="flex items-start gap-2">
                                <svg class="mt-0.5 size-4 shrink-0 text-chrome-300" viewBox="0 0 20 20" fill="currentColor">{!! $icons['vehicle'] !!}</svg>
                                <span class="text-chrome-600">{{ $carLabel ? __($carLabel) : '—' }}@if ((int) ($num_cars ?: 1) > 1) <span class="text-chrome-400">× {{ (int) $num_cars }}</span>@endif</span>
                            </div>
                        </div>

                        {{-- Charges --}}
                        <dl class="space-y-2 border-t border-chrome-100 pt-4 text-sm">
                            <div class="flex justify-between"><dt class="text-chrome-500">{{ __('Amount') }}</dt><dd class="font-medium text-chrome-800">{{ ValueFormat::money((float) ($amount === '' ? '0' : $amount)) }}</dd></div>
                            <div class="flex justify-between"><dt class="text-chrome-500">{{ __('Discount') }}</dt><dd class="text-chrome-600">− {{ ValueFormat::money((float) ($discount === '' ? '0' : $discount)) }}</dd></div>
                            <div class="mt-1 flex items-center justify-between border-t border-chrome-100 pt-3"><dt class="font-semibold text-chrome-700">{{ __('Net amount') }}</dt><dd class="text-lg font-bold text-chrome-900">{{ ValueFormat::money($netPreview) }}</dd></div>
                            <div class="flex justify-between"><dt class="text-chrome-500">{{ __('Advance') }}</dt><dd class="text-chrome-600">− {{ ValueFormat::money($advNum) }}</dd></div>
                            <div class="flex items-center justify-between rounded-lg bg-primary-50 px-3 py-2"><dt class="font-semibold text-primary-800">{{ __('Balance') }}</dt><dd class="text-base font-bold text-primary-700">{{ ValueFormat::money($balancePreview) }}</dd></div>
                            @if ($payLabel)<div class="flex justify-between pt-1 text-xs"><dt class="text-chrome-400">{{ __('Payment method') }}</dt><dd class="text-chrome-500">{{ __($payLabel) }}</dd></div>@endif
                        </dl>

                        <button wire:click="save" class="o-btn-primary mt-5 w-full justify-center py-2.5">
                            <span wire:loading.remove wire:target="save">{{ $isEditing ? __('Save booking') : __('Add record') }}</span>
                            <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
                        </button>
                        <a href="{{ url('/app/limousine/booking') }}" wire:navigate class="mt-2 block text-center text-sm text-chrome-500 hover:text-chrome-700">{{ __('Cancel') }}</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @include('limousine::partials.customer-modal')
</div>
