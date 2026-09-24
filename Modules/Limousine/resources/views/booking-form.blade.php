<div class="mx-auto max-w-5xl p-4 sm:p-6">
    <x-form-breadcrumb :parent="__('Bookings')" :parent-url="url('/app/limousine/booking')" :current="$isEditing ? ($reference ?: __('Booking')) : __('New booking')" />

    @php
        $statusBadge = [
            'queue' => 'bg-amber-100 text-amber-700',
            'confirmed' => 'bg-sky-100 text-sky-700',
            'active' => 'bg-indigo-100 text-indigo-700',
            'completed' => 'bg-emerald-100 text-emerald-700',
            'cancelled' => 'bg-red-100 text-red-700',
        ][$status] ?? 'bg-chrome-200 text-chrome-700';
        $lbl = 'mb-1 block text-sm font-medium text-chrome-700';
    @endphp

    @if ($isEditing)
        <div x-data="{ askingCancel: false }"
            class="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/[0.06]">
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
                    {{-- Asked in the page rather than through `wire:confirm`, which
                         hands the browser its own dialog: on an action already
                         called "Cancel", the OS buttons read "Cancel" and "OK" —
                         one of which looks like it means "don't cancel". The
                         office asked for a plain Yes / No. Dismiss sits at the
                         start, the action at the end, like every other dialog. --}}
                    <button type="button" x-on:click="askingCancel = true" class="text-sm font-medium text-red-600 hover:underline">{{ __('Cancel') }}</button>
                @endif
            </div>

            @if (! in_array($status, ['completed', 'cancelled'], true))
                <div x-cloak x-show="askingCancel" class="fixed inset-0 z-50 flex items-center justify-center p-4"
                    x-on:keydown.escape.window="askingCancel = false">
                    <div class="absolute inset-0 bg-chrome-900/40" x-on:click="askingCancel = false"></div>
                    <div class="relative w-full max-w-sm rounded-xl bg-white p-5 shadow-pop ring-1 ring-chrome-900/5">
                        <h3 class="text-sm font-semibold text-chrome-800">{{ __('Cancel this booking?') }}</h3>
                        <div class="mt-5 flex items-center justify-between gap-3">
                            <button type="button" x-on:click="askingCancel = false" class="o-btn-ghost text-sm">{{ __('No') }}</button>
                            <button type="button" x-on:click="askingCancel = false; $wire.cancelBooking()" wire:loading.attr="disabled"
                                class="rounded-lg bg-red-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-red-700">{{ __('Yes') }}</button>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    @endif

    {{-- ── Customer & passenger ── --}}
    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/[0.06] sm:p-6">
        <h2 class="mb-4 text-sm font-semibold text-chrome-800">{{ __('Customer & passenger') }}</h2>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <div class="mb-1 flex items-center justify-between gap-2">
                    <label class="block text-sm font-medium text-chrome-700">{{ __('Customer') }} *</label>
                    <button type="button" wire:click="openCustomerModal" class="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-xs font-medium text-primary-700 hover:bg-primary-50">
                        <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor"><path d="M10 5a1 1 0 0 1 1 1v3h3a1 1 0 1 1 0 2h-3v3a1 1 0 1 1-2 0v-3H6a1 1 0 1 1 0-2h3V6a1 1 0 0 1 1-1Z"/></svg>
                        {{ __('New customer') }}
                    </button>
                </div>
                {{-- .live so picking a customer fills the passenger block straight
                     away. Searchable because this is every customer there has ever
                     been: scrolling to a name you already know is not a way to
                     pick it. --}}
                <x-searchable-select wire:model.live="customer_id" class="o-input w-full"
                    :options="collect($customers)->map(fn ($c) => [
                        'value' => $c->id,
                        'label' => $c->name . ($c->phone ? ' · ' . $c->phone : ''),
                    ])->all()"
                    :search-placeholder="__('Search name or number…')" />
                @error('customer_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="{{ $lbl }}">{{ __('Booking type') }}</label>
                <select wire:model="booking_type" class="o-input w-full">
                    <option value="">{{ __('— Select —') }}</option>
                    @foreach ($bookingTypes as $opt)<option value="{{ $opt['value'] }}">{{ __($opt['label']) }}</option>@endforeach
                </select>
            </div>
            <div>
                <label class="{{ $lbl }}">{{ __('Company reference') }}</label>
                <input type="text" wire:model="company_reference" class="o-input w-full">
            </div>
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
            <div>
                <label class="{{ $lbl }}">{{ __('Requested by') }} *</label>
                <input type="text" wire:model="requested_by" class="o-input w-full">
                @error('requested_by') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="{{ $lbl }}">{{ __('Prepared by') }}</label>
                {{-- Stamped from the signed-in user. No wire:model: the property is
                     #[Locked], so binding it would only invite a tampering error. --}}
                <input type="text" value="{{ $prepared_by }}" readonly tabindex="-1"
                       class="o-input w-full cursor-not-allowed opacity-70">
                <p class="mt-1 text-xs text-chrome-500">{{ __('Recorded automatically from your account.') }}</p>
            </div>
            <div class="sm:col-span-2">
                <label class="{{ $lbl }}">{{ __('Comments') }}</label>
                <textarea wire:model="notes" rows="2" class="o-input w-full"></textarea>
            </div>
        </div>
    </div>

    {{-- ── Trip legs ── --}}
    <div class="mt-5">
        {{-- No car on the booking sheet at all: the vehicle is unknown when the
             trip is taken and is assigned from the Bookings list (Queue tab).
             Quotations include this partial without the flag and keep theirs. --}}
        @include('limousine::partials.legs', ['showCar' => false, 'calendar' => true])
    </div>

    {{-- ── Payment & total ── --}}
    <div class="mt-5 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/[0.06] sm:p-6">
        <h2 class="mb-4 text-sm font-semibold text-chrome-800">{{ __('Payment') }}</h2>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <label class="{{ $lbl }}">{{ __('Advance (BHD)') }}</label>
                <input type="number" step="0.001" min="0" wire:model.live="advance" class="o-input w-full">
                {{-- Credit from a cancelled trip counts as money already taken, so
                     applying it raises the advance and the balance falls through the
                     same path a cash payment takes. --}}
                <div class="mt-2 flex gap-2">
                    <input type="text" wire:model="couponCode" wire:keydown.enter.prevent="applyCoupon"
                           class="o-input w-full text-sm"
                           placeholder="{{ __('Refund coupon code') }}">
                    <button type="button" wire:click="applyCoupon" class="o-btn-ghost shrink-0 text-sm">{{ __('Apply') }}</button>
                </div>
                @error('couponCode') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                {{-- Checked, and showing against the total. It is taken off the
                     coupon when the booking is saved — there is no bill to take
                     it off before then, and an abandoned form must not quietly
                     consume somebody's credit. --}}
                @if ($couponCredit > 0)
                    <p class="mt-1 text-xs font-medium text-emerald-700">
                        {{ __(':amount comes off this booking. Taken from the coupon when you save.', [
                            'amount' => \App\Erp\Views\ValueFormat::money($couponCredit),
                        ]) }}
                    </p>
                @endif
                <p class="mt-1 text-[11px]">
                    <a href="{{ url('/app/limousine/coupon') }}" class="text-primary-700 hover:underline">{{ __('Refund coupons') }}</a>
                </p>
            </div>
            <div>
                <label class="{{ $lbl }}">{{ __('Payment method') }} *</label>
                <div class="flex flex-wrap gap-2">
                    @foreach ($paymentMethods as $pm)
                        {{-- `relative` matters: the sr-only input is position:absolute, so
                             without a positioned ancestor it anchors to a distant one. Clicking
                             the label focuses that hidden radio, the browser scrolls it into
                             view, and the page jumps. Anchoring it here keeps the scroll still. --}}
                        <label class="relative cursor-pointer" wire:key="pm-{{ $pm['value'] }}">
                            <input type="radio" wire:model.live="payment_method" value="{{ $pm['value'] }}" class="peer sr-only">
                            <span class="block rounded-lg border border-chrome-200 px-3 py-1.5 text-sm text-chrome-600 transition hover:bg-chrome-50 peer-checked:border-primary-500 peer-checked:bg-primary-50 peer-checked:font-medium peer-checked:text-primary-700">{{ __($pm['label']) }}</span>
                        </label>
                    @endforeach
                </div>
                @error('payment_method') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
        </div>

        <dl class="mt-4 space-y-2 border-t border-chrome-100 pt-4 text-sm">
            <div class="flex justify-between"><dt class="text-chrome-500">{{ __('Grand total') }} <span class="text-chrome-400">· {{ count($legs) }} {{ __('leg(s)') }}</span></dt><dd class="text-lg font-bold text-chrome-900">{{ \App\Erp\Views\ValueFormat::money($grandTotal) }}</dd></div>
            <div class="flex justify-between"><dt class="text-chrome-500">{{ __('Advance') }}</dt><dd class="text-chrome-600">− {{ \App\Erp\Views\ValueFormat::money((float) ($advance === '' ? '0' : $advance)) }}</dd></div>
            @if ($couponCredit > 0)
                <div class="flex justify-between">
                    <dt class="text-chrome-500">{{ __('Coupon') }} <span class="text-chrome-400">· {{ $couponAccepted }}</span></dt>
                    <dd class="font-medium text-emerald-700">− {{ \App\Erp\Views\ValueFormat::money($couponCredit) }}</dd>
                </div>
            @endif
            <div class="flex items-center justify-between rounded-lg bg-primary-50 px-3 py-2"><dt class="font-semibold text-primary-800">{{ __('Balance') }}</dt><dd class="text-base font-bold text-primary-700">{{ \App\Erp\Views\ValueFormat::money($balance) }}</dd></div>
        </dl>

        <button wire:click="save" class="o-btn-primary mt-4 w-full justify-center py-2.5">
            <span wire:loading.remove wire:target="save">{{ $isEditing ? __('Save booking') : __('Add record') }}</span>
            <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
        </button>
        <a href="{{ url('/app/limousine/booking') }}" wire:navigate class="mt-2 block text-center text-sm text-chrome-500 hover:text-chrome-700">{{ __('Cancel') }}</a>
    </div>

    @include('limousine::partials.customer-modal')
</div>
