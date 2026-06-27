@php use App\Erp\Views\ValueFormat; @endphp
@php use Modules\Rental\Models\RentalOrder; @endphp
@php $lbl = 'mb-1.5 block text-xs font-medium uppercase tracking-wide text-chrome-500'; @endphp
<div class="mx-auto max-w-6xl p-4 sm:p-6">
    {{-- Breadcrumb + title --}}
    <div class="mb-5">
        <div class="flex items-center gap-2 text-sm text-chrome-400">
            <a href="{{ url('/app/rental/order') }}" wire:navigate class="hover:text-primary-700">{{ __('Orders') }}</a>
            <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.168 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd"/></svg>
            <span class="font-medium text-chrome-600">{{ $isEditing ? ($reference ?: __('Order')) : __('New order') }}</span>
        </div>
        <h1 class="mt-1 text-xl font-bold text-chrome-900">{{ $isEditing ? __('Rental order') : __('New rental order') }}</h1>
    </div>

    @php
        $stateBadge = [
            'draft' => 'bg-chrome-200 text-chrome-700',
            'active' => 'bg-sky-100 text-sky-700',
            'closed' => 'bg-emerald-100 text-emerald-700',
            'cancelled' => 'bg-red-100 text-red-700',
        ][$state] ?? 'bg-chrome-200 text-chrome-700';
    @endphp

    {{-- Status / actions panel (existing orders only) --}}
    @if ($isEditing)
        <div class="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/5">
            <div class="flex flex-wrap items-center gap-2.5">
                <span class="text-sm font-semibold text-chrome-800">{{ $reference }}</span>
                <span class="rounded-full px-2.5 py-0.5 text-[11px] font-semibold uppercase tracking-wide {{ $stateBadge }}">{{ __(ucfirst($state)) }}</span>
                @if ($savedOrder?->createdBy)
                    <span class="text-[11px] text-chrome-400">{{ __('Created by') }} {{ $savedOrder->createdBy->name }} · {{ $savedOrder->created_at?->format('Y-m-d') }}</span>
                @endif
                @php
                    // Paid+confirmed → green; paid-but-unconfirmed / partial / unpaid → amber.
                    $confirmed = $payment_status === 'paid' && $payment_confirmed;
                    $payBadge = $confirmed ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700';
                    $payLabel = match (true) {
                        $confirmed => __('Paid'),
                        $payment_status === 'paid' => __('Paid · unconfirmed'),
                        $payment_status === 'partial' => __('Partial'),
                        default => __('Unpaid'),
                    };
                @endphp
                <span class="rounded-full px-2.5 py-0.5 text-[11px] font-semibold uppercase tracking-wide {{ $payBadge }}">{{ $payLabel }}</span>
                @if ($confirmed && $savedOrder?->confirmedBy)
                    <span class="text-[11px] text-chrome-400">{{ __('by') }} {{ $savedOrder->confirmedBy->name }} · {{ $savedOrder->confirmed_at?->format('Y-m-d H:i') }}</span>
                @elseif ($payment_status === 'paid' && ! $payment_confirmed)
                    <span class="text-[11px] text-amber-600">{{ __('Awaiting accountant confirmation') }}</span>
                @endif
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @if ($state === 'draft')
                    <button wire:click="startRental" class="o-btn-primary text-sm">{{ __('Start rental') }}</button>
                @elseif ($state === 'active')
                    <button wire:click="closeRental" class="o-btn-primary text-sm">{{ __('Close rental') }}</button>
                @endif

                {{-- Swap the car mid-rental (only while it's actually out). --}}
                @if ($savedOrder && $state === 'active' && $savedOrder->started_at && ! $savedOrder->returned_at)
                    <a href="{{ url('/app/rental/replacement/new?order=' . $id) }}" wire:navigate class="o-btn-ghost text-sm">{{ __('Replace car') }}</a>
                @endif

                @if ($state !== 'cancelled')
                    <button wire:click="createInvoice" class="o-btn-ghost text-sm">{{ __('Create invoice') }}</button>
                @endif

                <a href="{{ url('/app/rental/order/' . $id . '/agreement') }}" target="_blank" rel="noopener" class="o-btn-ghost text-sm">{{ __('Print agreement') }}</a>
                @if ($savedOrder?->agreement_emailed_at)
                    <span class="inline-flex items-center gap-1 text-sm text-emerald-600">
                        <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 0 1 0 1.4l-7.5 7.5a1 1 0 0 1-1.4 0l-3.5-3.5a1 1 0 1 1 1.4-1.4l2.8 2.79 6.8-6.79a1 1 0 0 1 1.4 0Z" clip-rule="evenodd"/></svg>
                        {{ __('Agreement sent') }} · {{ $savedOrder->agreement_emailed_at->format('Y-m-d H:i') }}
                    </span>
                @else
                    <button wire:click="emailAgreement" wire:confirm="{{ __('Email the agreement PDF to the customer? (sends once)') }}" class="o-btn-ghost text-sm">{{ __('Email agreement') }}</button>
                @endif
                <a href="{{ url('/app/rental/order/' . $id . '/agreement/pdf') }}" target="_blank" rel="noopener" class="text-sm font-medium text-chrome-500 hover:underline">{{ __('PDF') }}</a>

                {{-- Payment confirmation — accountant / super-admin only. --}}
                @if ($canConfirmPayment)
                    @if ($payment_status === 'paid' && ! $payment_confirmed)
                        <button wire:click="confirmPayment" class="o-btn-primary text-sm">{{ __('Confirm payment') }}</button>
                    @elseif ($payment_confirmed)
                        <button wire:click="unconfirmPayment" class="text-sm font-medium text-chrome-500 hover:underline">{{ __('Unconfirm') }}</button>
                    @endif
                @endif

                @if (in_array($state, ['draft', 'active'], true))
                    <button wire:click="cancelOrder"
                        wire:confirm="{{ __('Cancel this order?') }}"
                        class="text-sm font-medium text-red-600 hover:underline">{{ __('Cancel order') }}</button>
                @endif
            </div>
        </div>
    @endif

    {{-- Handover / Return inspection summary (saved orders) --}}
    @if ($savedOrder && ($savedOrder->started_at || $savedOrder->returned_at))
        <div class="mb-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
            @if ($savedOrder->started_at)
                <div class="rounded-2xl bg-white p-4 text-sm shadow-sm ring-1 ring-chrome-900/5">
                    <h3 class="mb-2 font-semibold text-chrome-800">{{ __('Handover') }} <span class="text-xs font-normal text-chrome-400">{{ $savedOrder->started_at->format('Y-m-d H:i') }}</span></h3>
                    <dl class="space-y-1 text-chrome-600">
                        <div class="flex justify-between"><dt>{{ __('KM') }}</dt><dd class="font-medium text-chrome-800">{{ $savedOrder->handover_km !== null ? number_format((float) $savedOrder->handover_km) : '—' }}</dd></div>
                        <div class="flex justify-between"><dt>{{ __('Fuel') }}</dt><dd class="font-medium text-chrome-800">{{ RentalOrder::fuelLabel($savedOrder->handover_fuel) }}</dd></div>
                        @if ($savedOrder->handover_notes)<div><dt class="text-chrome-400">{{ __('Condition') }}</dt><dd class="text-chrome-700">{{ $savedOrder->handover_notes }}</dd></div>@endif
                        @if ($savedOrder->handover_video_url)<a href="{{ $savedOrder->handover_video_url }}" target="_blank" rel="noopener" class="inline-block text-primary-700 hover:underline">{{ __('View video') }} ↗</a>@endif
                    </dl>
                </div>
            @endif
            @if ($savedOrder->returned_at)
                <div class="rounded-2xl bg-white p-4 text-sm shadow-sm ring-1 ring-chrome-900/5">
                    <h3 class="mb-2 font-semibold text-chrome-800">{{ __('Return') }} <span class="text-xs font-normal text-chrome-400">{{ $savedOrder->returned_at->format('Y-m-d H:i') }}</span></h3>
                    <dl class="space-y-1 text-chrome-600">
                        <div class="flex justify-between"><dt>{{ __('KM') }}</dt><dd class="font-medium text-chrome-800">{{ $savedOrder->return_km !== null ? number_format((float) $savedOrder->return_km) : '—' }}</dd></div>
                        <div class="flex justify-between"><dt>{{ __('Fuel') }}</dt><dd class="font-medium text-chrome-800">{{ RentalOrder::fuelLabel($savedOrder->return_fuel) }}</dd></div>
                        @if ($savedOrder->fuelChargeTotal() > 0)
                            <div class="flex justify-between"><dt>{{ __('Fuel charge') }}</dt><dd class="font-medium text-amber-700">{{ ValueFormat::money($savedOrder->fuelChargeTotal()) }}</dd></div>
                        @endif
                        @if ($savedOrder->extra_charge > 0)
                            <div class="flex justify-between"><dt>{{ __('Extra charge') }}{{ $savedOrder->extra_charge_note ? ' · ' . $savedOrder->extra_charge_note : '' }}</dt><dd class="font-medium text-amber-700">{{ ValueFormat::money($savedOrder->extra_charge) }}</dd></div>
                        @endif
                        <div class="flex justify-between"><dt>{{ __('Damage') }}</dt><dd class="font-medium {{ $savedOrder->has_damage ? 'text-red-600' : 'text-emerald-600' }}">{{ $savedOrder->has_damage ? __('Yes') : __('No') }}</dd></div>
                        @if ($savedOrder->damage_notes)<div><dt class="text-chrome-400">{{ __('Damage notes') }}</dt><dd class="text-chrome-700">{{ $savedOrder->damage_notes }}</dd></div>@endif
                        @if ($savedOrder->damage_video_url)<a href="{{ $savedOrder->damage_video_url }}" target="_blank" rel="noopener" class="inline-block text-primary-700 hover:underline">{{ __('View damage video') }} ↗</a>@endif
                        @if ($savedOrder->return_video_url)<a href="{{ $savedOrder->return_video_url }}" target="_blank" rel="noopener" class="inline-block text-primary-700 hover:underline">{{ __('View return video') }} ↗</a>@endif
                    </dl>
                </div>
            @endif
        </div>
    @endif

    {{-- Security deposit — held after return, then settled by an accountant. --}}
    @if ($savedOrder && $savedOrder->deposit > 0)
        @php
            $depBadge = [
                'held' => 'bg-amber-100 text-amber-700',
                'refunded' => 'bg-emerald-100 text-emerald-700',
                'partial' => 'bg-amber-100 text-amber-700',
                'forfeited' => 'bg-red-100 text-red-700',
            ][$savedOrder->deposit_status] ?? 'bg-chrome-200 text-chrome-700';
            $depLabel = [
                'held' => __('Held'),
                'refunded' => __('Refunded'),
                'partial' => __('Partially refunded'),
                'forfeited' => __('Non-refundable'),
            ][$savedOrder->deposit_status] ?? __('Held');
        @endphp
        <div class="mb-5 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex flex-wrap items-center gap-2.5">
                    <h3 class="text-sm font-semibold text-chrome-800">{{ __('Security deposit') }}</h3>
                    <span class="text-sm font-medium text-chrome-700">{{ ValueFormat::money($savedOrder->deposit) }}</span>
                    <span class="rounded-full px-2.5 py-0.5 text-[11px] font-semibold uppercase tracking-wide {{ $depBadge }}">{{ $depLabel }}</span>
                    @if ($savedOrder->depositPending() && $savedOrder->depositHoldUntil())
                        <span class="text-[11px] text-chrome-400">{{ __('Refundable from') }} {{ $savedOrder->depositHoldUntil()->format('Y-m-d') }}</span>
                    @endif
                </div>
                @if ($savedOrder->depositPending())
                    @if ($canConfirmPayment)
                        @if ($savedOrder->depositHoldElapsed())
                            <button wire:click="settleDeposit" class="o-btn-primary text-sm">{{ __('Settle deposit') }}</button>
                        @elseif ($isSuperAdmin)
                            <button wire:click="settleDeposit" wire:confirm="{{ __('Settle this deposit before the 14-day hold ends?') }}" class="o-btn-primary text-sm">{{ __('Settle early') }}</button>
                        @else
                            <span class="inline-flex items-center gap-1 text-[11px] font-medium text-chrome-500">
                                <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 1a4.5 4.5 0 0 0-4.5 4.5V9H5a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2h-.5V5.5A4.5 4.5 0 0 0 10 1Zm3 8V5.5a3 3 0 1 0-6 0V9h6Z" clip-rule="evenodd"/></svg>
                                {{ __('Locked until') }} {{ $savedOrder->depositHoldUntil()?->format('Y-m-d') }}
                            </span>
                        @endif
                    @else
                        <span class="text-[11px] text-amber-600">{{ __('Awaiting accountant settlement') }}</span>
                    @endif
                @endif
            </div>

            @unless ($savedOrder->depositPending())
                <dl class="mt-3 grid grid-cols-2 gap-x-6 gap-y-1 text-sm text-chrome-600 sm:grid-cols-3">
                    <div class="flex justify-between"><dt>{{ __('Returned') }}</dt><dd class="font-medium text-emerald-700">{{ ValueFormat::money($savedOrder->depositRefundAmount()) }}</dd></div>
                    @if ($savedOrder->deposit_deducted > 0)
                        <div class="flex justify-between"><dt>{{ __('Deducted') }}</dt><dd class="font-medium text-red-600">{{ ValueFormat::money($savedOrder->deposit_deducted) }}</dd></div>
                    @endif
                    @if ($savedOrder->depositResolvedBy)
                        <div class="flex justify-between"><dt>{{ __('By') }}</dt><dd class="text-chrome-500">{{ $savedOrder->depositResolvedBy->name }} · {{ $savedOrder->deposit_resolved_at?->format('Y-m-d') }}</dd></div>
                    @endif
                </dl>
                @if ($savedOrder->deposit_reason)
                    <p class="mt-2 text-sm text-chrome-700"><span class="text-chrome-400">{{ __('Reason') }}:</span> {{ $savedOrder->deposit_reason }}</p>
                @endif
                @if (! empty($savedOrder->deposit_images))
                    <div class="mt-2 flex flex-wrap gap-2">
                        @foreach ($savedOrder->deposit_images as $img)
                            <a href="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($img) }}" target="_blank" rel="noopener">
                                <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($img) }}" alt="{{ __('Deposit evidence') }}" class="size-16 rounded-lg object-cover ring-1 ring-chrome-900/10">
                            </a>
                        @endforeach
                    </div>
                @endif
            @endunless
        </div>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        {{-- ─────────────── Form ─────────────── --}}
        <div class="space-y-5 lg:col-span-2">

            {{-- Customer & contract --}}
            <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5 sm:p-6">
                <header class="mb-4 flex items-center gap-2.5">
                    <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary-50 text-primary-600">
                        <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10 8a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm-7 9a7 7 0 0 1 14 0H3Z"/></svg>
                    </span>
                    <div>
                        <h2 class="text-sm font-semibold text-chrome-800">{{ __('Customer & contract') }}</h2>
                        <p class="text-xs text-chrome-400">{{ __('Who is renting, and the contract reference.') }}</p>
                    </div>
                </header>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label class="{{ $lbl }}">{{ __('Order number') }}</label>
                        <input type="text" value="{{ $reference ?: __('Auto') }}" class="o-input w-full bg-chrome-50 text-chrome-500" disabled>
                    </div>
                    <div>
                        <label class="{{ $lbl }}">{{ __('Date') }}</label>
                        <input type="date" wire:model="order_date" class="o-input w-full">
                    </div>

                    <div>
                        <div class="mb-1.5 flex items-center justify-between gap-2">
                            <label class="text-xs font-medium uppercase tracking-wide text-chrome-500">{{ __('Customer') }} <span class="text-red-500">*</span></label>
                            <button type="button" wire:click="openCustomerModal"
                                class="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-xs font-medium text-primary-700 hover:bg-primary-50">
                                <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor"><path d="M10 5a1 1 0 0 1 1 1v3h3a1 1 0 1 1 0 2h-3v3a1 1 0 1 1-2 0v-3H6a1 1 0 1 1 0-2h3V6a1 1 0 0 1 1-1Z"/></svg>
                                {{ __('New customer') }}
                            </button>
                        </div>
                        <select wire:model.live="customer_id" class="o-input w-full">
                            <option value="">{{ __('— Select —') }}</option>
                            @foreach ($customers as $c)
                                <option value="{{ $c->id }}">{{ $c->name }}{{ $c->phone ? ' · ' . $c->phone : '' }}</option>
                            @endforeach
                        </select>
                        @error('customer_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="{{ $lbl }}">{{ __('Phone') }}</label>
                        <input type="text" wire:model="phone" placeholder="{{ __('Customer phone number') }}" class="o-input w-full">
                    </div>

                    <div>
                        <label class="{{ $lbl }}">{{ __('Additional driver') }}</label>
                        <input type="text" wire:model="additional_driver" placeholder="{{ __('Additional driver') }}" class="o-input w-full">
                    </div>
                    <div>
                        <label class="{{ $lbl }}">{{ __('Licence number') }}</label>
                        <input type="text" wire:model="additional_driver_license" placeholder="{{ __("Additional driver's licence number") }}" class="o-input w-full">
                    </div>
                </div>
            </section>

            {{-- Vehicle --}}
            <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5 sm:p-6">
                <header class="mb-4 flex items-center gap-2.5">
                    <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary-50 text-primary-600">
                        <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path d="M3 9.5 4.2 6.6A2 2 0 0 1 6 5.5h8a2 2 0 0 1 1.8 1.1L17 9.5a2 2 0 0 1 1 1.7V13a1 1 0 0 1-1 1h-1a2 2 0 1 1-4 0H8a2 2 0 1 1-4 0H3a1 1 0 0 1-1-1v-1.8a2 2 0 0 1 1-1.7Z"/><circle cx="6.5" cy="14" r="1.5"/><circle cx="13.5" cy="14" r="1.5"/></svg>
                    </span>
                    <div>
                        <h2 class="text-sm font-semibold text-chrome-800">{{ __('Car') }}</h2>
                        <p class="text-xs text-chrome-400">{{ __('The car, branch and delivery.') }}</p>
                    </div>
                </header>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label class="{{ $lbl }}">{{ __('Car') }} <span class="text-red-500">*</span></label>
                        <select wire:model.live="vehicle_id" class="o-input w-full">
                            <option value="">{{ __('— Select —') }}</option>
                            @foreach ($vehicles as $v)
                                <option value="{{ $v->id }}">{{ $v->displayName() }} · {{ $v->is_outside ? __('Outside') : __('Ours') }} ({{ __(ucfirst($v->status)) }}){{ $v->needsRenewal() ? ' — ' . __('papers expired') : '' }}</option>
                            @endforeach
                        </select>
                        @error('vehicle_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        @if ($selectedVehicle && $selectedVehicle->needsRenewal())
                            <p class="mt-1 text-xs font-medium text-red-600">{{ __('This car’s registration/insurance has lapsed — renew it before renting (super-admin override only).') }}</p>
                        @elseif ($selectedVehicle && $selectedVehicle->expiringSoon())
                            <p class="mt-1 text-xs text-amber-600">{{ __('Papers expire on :date.', ['date' => $selectedVehicle->nextDocExpiry()?->format('Y-m-d')]) }}</p>
                        @endif
                    </div>
                    <div>
                        <label class="{{ $lbl }}">{{ __('KM') }}</label>
                        <input type="number" min="0" wire:model="pickup_mileage" placeholder="{{ __('Current KM') }}" class="o-input w-full">
                    </div>
                    <div>
                        <label class="{{ $lbl }}">{{ __('Branch') }}</label>
                        <select wire:model="branch_id" class="o-input w-full">
                            <option value="">{{ __('— Select —') }}</option>
                            @foreach ($branches as $b)
                                <option value="{{ $b->id }}">{{ $b->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="{{ $lbl }}">{{ __('Delivery') }}</label>
                        <div class="space-y-2">
                            <label class="flex items-center gap-2 rounded-lg border border-chrome-200 px-3 py-2 text-sm text-chrome-700 has-[:checked]:border-primary-400 has-[:checked]:bg-primary-50/40">
                                <input type="checkbox" wire:model.live="delivery" class="rounded border-chrome-300 text-primary-600">
                                {{ __('Drop-off — deliver the car') }}
                                <span class="ms-auto text-xs text-chrome-400">+ {{ ValueFormat::money(RentalOrder::DELIVERY_FEE) }}</span>
                            </label>
                            <label class="flex items-center gap-2 rounded-lg border border-chrome-200 px-3 py-2 text-sm text-chrome-700 has-[:checked]:border-primary-400 has-[:checked]:bg-primary-50/40">
                                <input type="checkbox" wire:model.live="pickup" class="rounded border-chrome-300 text-primary-600">
                                {{ __('Pick-up — collect the car') }}
                                <span class="ms-auto text-xs text-chrome-400">+ {{ ValueFormat::money(RentalOrder::PICKUP_FEE) }}</span>
                            </label>
                        </div>
                        @if ($delivery || $pickup)
                            <input type="text" wire:model="delivery_location" class="o-input mt-2 w-full" placeholder="{{ __('Drop-off / pick-up location') }}">
                            @error('delivery_location') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        @endif
                    </div>
                </div>

                {{-- Selected vehicle read-out --}}
                @if ($selectedVehicle)
                    <div class="mt-4 rounded-xl border border-chrome-100 bg-chrome-50/70 p-3">
                        <div class="grid grid-cols-2 gap-x-4 gap-y-2.5 text-xs sm:grid-cols-4">
                            @php
                                $statusTint = [
                                    'available' => 'bg-emerald-100 text-emerald-700',
                                    'rented' => 'bg-sky-100 text-sky-700',
                                    'reserved' => 'bg-violet-100 text-violet-700',
                                    'maintenance' => 'bg-amber-100 text-amber-700',
                                ][$selectedVehicle->status] ?? 'bg-chrome-200 text-chrome-700';
                                $readout = [
                                    __('Car type') => $selectedVehicle->category ? __(ucfirst($selectedVehicle->category)) : '—',
                                    __('Fuel type') => $selectedVehicle->fuel_type ? __(ucfirst($selectedVehicle->fuel_type)) : '—',
                                    __('Year') => $selectedVehicle->year ?: '—',
                                    __('Colour') => $selectedVehicle->color ?: '—',
                                    __('KM') => $selectedVehicle->odometer !== null ? number_format((float) $selectedVehicle->odometer) : '—',
                                    __('Next maint. date') => $selectedVehicle->next_maintenance_date?->format('Y-m-d') ?? '—',
                                    __('Next maint. KM') => $selectedVehicle->next_maintenance_mileage !== null ? number_format((float) $selectedVehicle->next_maintenance_mileage) : '—',
                                ];
                            @endphp
                            <div>
                                <div class="uppercase tracking-wide text-chrome-400">{{ __('Reservation status') }}</div>
                                <span class="mt-0.5 inline-block rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide {{ $statusTint }}">{{ __(ucfirst($selectedVehicle->status)) }}</span>
                            </div>
                            @foreach ($readout as $label => $value)
                                <div>
                                    <div class="uppercase tracking-wide text-chrome-400">{{ $label }}</div>
                                    <div class="font-medium text-chrome-800">{{ $value }}</div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </section>

            {{-- Rental period & rate --}}
            <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5 sm:p-6">
                <header class="mb-4 flex items-center gap-2.5">
                    <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary-50 text-primary-600">
                        <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.75 2a.75.75 0 0 1 .75.75V4h7V2.75a.75.75 0 0 1 1.5 0V4h.25A2.75 2.75 0 0 1 18 6.75v8.5A2.75 2.75 0 0 1 15.25 18H4.75A2.75 2.75 0 0 1 2 15.25v-8.5A2.75 2.75 0 0 1 4.75 4H5V2.75A.75.75 0 0 1 5.75 2ZM3.5 8.5v6.75c0 .69.56 1.25 1.25 1.25h10.5c.69 0 1.25-.56 1.25-1.25V8.5h-13Z" clip-rule="evenodd"/></svg>
                    </span>
                    <div>
                        <h2 class="text-sm font-semibold text-chrome-800">{{ __('Rental period & rate') }}</h2>
                        <p class="text-xs text-chrome-400">{{ __('Dates, time and the pricing.') }}</p>
                    </div>
                </header>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div>
                        <label class="{{ $lbl }}">{{ __('Pick-up date') }} <span class="text-red-500">*</span></label>
                        <input type="date" wire:model.live="start_date" class="o-input w-full" @unless ($canBackdate) min="{{ now()->toDateString() }}" @endunless>
                        @error('start_date') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="{{ $lbl }}">{{ __('Return date') }} <span class="text-red-500">*</span></label>
                        <input type="date" wire:model.live="end_date" class="o-input w-full" @if ($start_date) min="{{ $start_date }}" @endif>
                        @error('end_date') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="{{ $lbl }}">{{ __('Hired time') }} <span class="text-red-500">*</span></label>
                        <input type="time" wire:model="hired_time" class="o-input w-full">
                        @error('hired_time') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="{{ $lbl }}">{{ __('Rate type') }} <span class="text-red-500">*</span></label>
                        <select wire:model.live="rate_type" class="o-input w-full">
                            <option value="daily">{{ __('Daily') }}</option>
                            <option value="weekly">{{ __('Weekly') }}</option>
                            <option value="monthly">{{ __('Monthly') }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="{{ $lbl }}">{{ __('Rate (BHD)') }} <span class="text-red-500">*</span></label>
                        <input type="number" step="0.001" min="0" wire:model.live="rate" class="o-input w-full">
                        @error('rate') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="{{ $lbl }}">{{ __('VAT') }} %</label>
                        <input type="text" value="{{ rtrim(rtrim(number_format(RentalOrder::DEFAULT_VAT_RATE, 2), '0'), '.') }}" class="o-input w-full bg-chrome-50 text-chrome-500" disabled>
                        <p class="mt-1 text-xs text-chrome-400">{{ __('Fixed rate') }}</p>
                    </div>
                </div>
            </section>

            {{-- Charges & payment --}}
            <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5 sm:p-6">
                <header class="mb-4 flex items-center gap-2.5">
                    <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary-50 text-primary-600">
                        <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path d="M1 4.25A2.25 2.25 0 0 1 3.25 2h13.5A2.25 2.25 0 0 1 19 4.25v1.5H1v-1.5ZM1 8h18v7.75A2.25 2.25 0 0 1 16.75 18H3.25A2.25 2.25 0 0 1 1 15.75V8Zm3 5.75a.75.75 0 0 1 .75-.75h3a.75.75 0 0 1 0 1.5h-3a.75.75 0 0 1-.75-.75Z"/></svg>
                    </span>
                    <div>
                        <h2 class="text-sm font-semibold text-chrome-800">{{ __('Charges & payment') }}</h2>
                        <p class="text-xs text-chrome-400">{{ __('Discounts, advance, deposit and method.') }}</p>
                    </div>
                </header>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div>
                        <label class="{{ $lbl }}">{{ __('Discount (BHD)') }}</label>
                        <input type="number" step="0.001" min="0" wire:model.live="discount" class="o-input w-full">
                    </div>
                    <div>
                        <label class="{{ $lbl }}">{{ __('Advance amount (BHD)') }}</label>
                        <input type="number" step="0.001" min="0" wire:model.live="advance_amount" class="o-input w-full">
                    </div>
                    <div>
                        <label class="{{ $lbl }}">{{ __('Deposit (BHD)') }}</label>
                        <input type="number" step="0.001" min="0" wire:model.live="deposit" class="o-input w-full">
                    </div>
                    {{-- Outside (rented-in) car: what we pay the vendor for this booking. --}}
                    @if ($selectedVehicle?->is_outside)
                        <div class="sm:col-span-2">
                            <label class="{{ $lbl }}">{{ __('Vendor cost — this booking (BHD)') }}</label>
                            <input type="number" step="0.001" min="0" wire:model.live="outside_cost" class="o-input w-full">
                            <p class="mt-1 text-xs text-chrome-400">{{ __('Outside car — revenue counts as total minus this cost.') }}</p>
                        </div>
                    @endif
                </div>

                <div class="mt-4">
                    <label class="{{ $lbl }}">{{ __('Payment type') }}</label>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($paymentTypes as $pt)
                            <label class="cursor-pointer">
                                <input type="radio" wire:model="payment_type" value="{{ $pt['value'] }}" class="peer sr-only">
                                <span class="block rounded-lg border border-chrome-200 px-3 py-1.5 text-sm text-chrome-600 transition hover:bg-chrome-50 peer-checked:border-primary-500 peer-checked:bg-primary-50 peer-checked:font-medium peer-checked:text-primary-700">{{ __($pt['label']) }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
            </section>

            {{-- Notes & documents --}}
            <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5 sm:p-6">
                <header class="mb-4 flex items-center gap-2.5">
                    <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary-50 text-primary-600">
                        <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4.5 2A1.5 1.5 0 0 0 3 3.5v13A1.5 1.5 0 0 0 4.5 18h11a1.5 1.5 0 0 0 1.5-1.5V7.621a1.5 1.5 0 0 0-.44-1.06l-3.62-3.622A1.5 1.5 0 0 0 11.378 2H4.5Zm2.25 8.5a.75.75 0 0 0 0 1.5h6.5a.75.75 0 0 0 0-1.5h-6.5Zm0 3a.75.75 0 0 0 0 1.5h6.5a.75.75 0 0 0 0-1.5h-6.5Z" clip-rule="evenodd"/></svg>
                    </span>
                    <div>
                        <h2 class="text-sm font-semibold text-chrome-800">{{ __('Notes & documents') }}</h2>
                        <p class="text-xs text-chrome-400">{{ __('Comments and the customer’s ID / licence.') }}</p>
                    </div>
                </header>

                <div>
                    <label class="{{ $lbl }}">{{ __('Comments') }}</label>
                    <textarea wire:model="notes" rows="2" class="o-input w-full"></textarea>
                </div>

                {{-- Direct (synchronous) image upload — Livewire's async upload
                     fails on Hostinger shared hosting, so the file is POSTed to
                     a controller and only the stored path is bound to Livewire. --}}
                <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                    @foreach ([
                        ['label' => __('CPR image'), 'prop' => 'cprImagePath', 'existing' => $existingCprImage],
                        ['label' => __('Licence image'), 'prop' => 'licenseImagePath', 'existing' => $existingLicenseImage],
                    ] as $doc)
                        <div>
                            <label class="{{ $lbl }}">{{ $doc['label'] }}</label>
                            <div class="rounded-xl border border-dashed border-chrome-200 p-3"
                                 x-data="{
                                     busy: false, error: '',
                                     preview: @js($doc['existing'] ? \Illuminate\Support\Facades\Storage::disk('public')->url($doc['existing']) : ''),
                                     async upload(e) {
                                         const file = e.target.files[0]; if (!file) return;
                                         this.busy = true; this.error = '';
                                         const data = new FormData(); data.append('file', file); data.append('bucket', 'rental_orders');
                                         try {
                                             const r = await fetch(@js(route('form.upload-image')), { method: 'POST', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' }, body: data, credentials: 'same-origin' });
                                             if (!r.ok) { const j = await r.json().catch(() => ({})); this.error = (j.errors && j.errors.file && j.errors.file[0]) || j.message || @js(__('Upload failed.')); return; }
                                             const j = await r.json(); this.preview = j.url; await $wire.set(@js($doc['prop']), j.path);
                                         } catch (err) { this.error = err.message || @js(__('Upload failed.')); } finally { this.busy = false; }
                                     },
                                 }">
                                <input type="file" accept="image/*" @change="upload($event)" class="block w-full text-sm text-chrome-600 file:mr-3 file:rounded-md file:border-0 file:bg-chrome-100 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-chrome-700 hover:file:bg-chrome-200">
                                <p x-show="busy" class="mt-1 text-xs text-chrome-400">{{ __('Uploading…') }}</p>
                                <p x-show="error" x-text="error" class="mt-1 text-xs text-red-600"></p>
                                <template x-if="preview"><img :src="preview" alt="" class="mt-2 h-24 rounded-lg object-cover ring-1 ring-chrome-200"></template>
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        </div>

        {{-- ─────────────── Live summary ─────────────── --}}
        <div class="lg:col-span-1">
            <div class="space-y-4 lg:sticky lg:top-6">
                <div class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-chrome-900/5">
                    <div class="flex items-center justify-between border-b border-chrome-100 bg-chrome-50/60 px-5 py-3">
                        <h2 class="text-sm font-semibold text-chrome-800">{{ __('Summary') }}</h2>
                        <span class="text-xs text-chrome-400">{{ $previewDays }} {{ __('days') }} · {{ $previewUnits }} {{ __('units') }}</span>
                    </div>
                    <div class="p-5">
                        <dl class="space-y-2 text-sm">
                            <div class="flex justify-between"><dt class="text-chrome-500">{{ __('Amount') }}</dt><dd class="font-medium text-chrome-800">{{ ValueFormat::money($previewSubtotal) }}</dd></div>
                            <div class="flex justify-between"><dt class="text-chrome-500">{{ __('Discount') }}</dt><dd class="text-chrome-600">− {{ ValueFormat::money((float) ($discount === '' ? '0' : $discount)) }}</dd></div>
                            <div class="flex justify-between"><dt class="text-chrome-500">{{ __('VAT') }}</dt><dd class="text-chrome-600">+ {{ ValueFormat::money($previewVat) }}</dd></div>
                            <div class="flex justify-between"><dt class="text-chrome-500">{{ __('Delivery charges') }}</dt><dd class="text-chrome-600">+ {{ ValueFormat::money($previewDelivery) }}</dd></div>
                            <div class="mt-1 flex items-center justify-between border-t border-chrome-100 pt-3"><dt class="font-semibold text-chrome-700">{{ __('Net total') }}</dt><dd class="text-lg font-bold text-chrome-900">{{ ValueFormat::money($previewTotal) }}</dd></div>
                            <div class="flex justify-between"><dt class="text-chrome-500">{{ __('Advance') }}</dt><dd class="text-chrome-600">− {{ ValueFormat::money((float) ($advance_amount === '' ? '0' : $advance_amount)) }}</dd></div>
                            <div class="flex items-center justify-between rounded-lg bg-primary-50 px-3 py-2"><dt class="font-semibold text-primary-800">{{ __('Balance') }}</dt><dd class="text-base font-bold text-primary-700">{{ ValueFormat::money($previewBalance) }}</dd></div>
                            <div class="flex justify-between pt-1 text-xs"><dt class="text-chrome-400">{{ __('Deposit (refundable)') }}</dt><dd class="text-chrome-500">{{ ValueFormat::money((float) ($deposit === '' ? '0' : $deposit)) }}</dd></div>
                        </dl>

                        <button wire:click="save" class="o-btn-primary mt-5 w-full justify-center py-2.5">
                            <span wire:loading.remove wire:target="save">{{ $isEditing ? __('Save order') : __('Add record') }}</span>
                            <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
                        </button>
                        <a href="{{ url('/app/rental/order') }}" wire:navigate class="mt-2 block text-center text-sm text-chrome-500 hover:text-chrome-700">{{ __('Cancel') }}</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Audit trail: who created / edited / approved this order, in order. --}}
    @if ($savedOrder)
        <div class="mt-6">
            <x-activity-trail :subject="$savedOrder" :empty="__('No activity recorded yet. Actions from now on (edits, handover, return, payments) will appear here with who and when.')" />
        </div>
    @endif

    @include('rental::partials.customer-modal')

    {{-- Handover modal --}}
    @if ($showHandover)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4" x-data x-on:keydown.escape.window="$wire.closeHandover()">
            <div class="absolute inset-0 bg-chrome-900/40" wire:click="closeHandover"></div>
            <div class="relative w-full max-w-md rounded-xl bg-white p-5 shadow-pop ring-1 ring-chrome-900/5">
                <h3 class="mb-1 text-sm font-semibold text-chrome-800">{{ __('Hand over the car') }}</h3>
                <p class="mb-3 text-xs text-chrome-500">{{ __('Record the car’s condition before giving it to the customer.') }}</p>
                <form wire:submit.prevent="confirmHandover" class="space-y-3">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('KM') }}</label>
                            <input type="number" min="0" wire:model="handover_km" class="o-input w-full">
                            @error('handover_km') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Fuel') }}</label>
                            <select wire:model="handover_fuel" class="o-input w-full">
                                @foreach ($fuelLevels as $f)<option value="{{ $f['value'] }}">{{ __($f['label']) }}</option>@endforeach
                            </select>
                            @error('handover_fuel') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Condition / problems') }}</label>
                        <textarea wire:model="handover_notes" rows="2" class="o-input w-full" placeholder="{{ __('e.g. check-engine light, scratch on bumper…') }}"></textarea>
                        @error('handover_notes') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Pickup video') }}</label>
                        <x-stream-video-upload target="handover_video_url" :url="$handover_video_url" />
                        <p class="mt-1 text-xs text-chrome-400">{{ __('Records the car at handover. Uploads to Cloudflare; share the link with the team.') }}</p>
                        @error('handover_video_url') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex justify-end gap-2 pt-1">
                        <button type="button" wire:click="closeHandover" class="text-sm text-chrome-500 hover:text-chrome-700">{{ __('Cancel') }}</button>
                        <button type="submit" class="o-btn-primary">{{ __('Confirm & start') }}</button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    {{-- Return modal --}}
    @if ($showReturn)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4" x-data x-on:keydown.escape.window="$wire.closeReturn()">
            <div class="absolute inset-0 bg-chrome-900/40" wire:click="closeReturn"></div>
            <div class="relative w-full max-w-md rounded-xl bg-white p-5 shadow-pop ring-1 ring-chrome-900/5">
                <h3 class="mb-1 text-sm font-semibold text-chrome-800">{{ __('Return the car') }}</h3>
                <p class="mb-3 text-xs text-chrome-500">{{ __('Record the car’s condition on return. The KM updates the car.') }}</p>
                <form wire:submit.prevent="confirmReturn" class="space-y-3">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Return KM') }} <span class="text-red-500">*</span></label>
                            <input type="number" wire:model="return_km" min="{{ $savedOrder?->handover_km ?? 0 }}" class="o-input w-full">
                            @error('return_km') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Return fuel') }}</label>
                            <select wire:model="return_fuel" class="o-input w-full">
                                @foreach ($fuelLevels as $f)<option value="{{ $f['value'] }}">{{ __($f['label']) }}</option>@endforeach
                            </select>
                            @if ($receivedFuel && $receivedFuel !== '—')
                                <p class="mt-1 text-xs text-chrome-400">{{ __('Received at') }} <span class="font-medium text-chrome-600">{{ $receivedFuel }}</span> — {{ __('should return the same') }}</p>
                            @endif
                            @error('return_fuel') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    {{-- Fuel shortfall charge: employee enters the refuel amount; a flat service fee is added. --}}
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Fuel charge (BHD)') }}</label>
                        <input type="number" step="0.001" min="0" wire:model.live="fuel_charge" class="o-input w-full" placeholder="0.000">
                        <p class="mt-1 text-xs text-chrome-400">
                            {{ __('If returned with less fuel, enter the refuel cost. A :fee service fee is added.', ['fee' => ValueFormat::money(RentalOrder::FUEL_SERVICE_FEE)]) }}
                            @if ((float) ($fuel_charge ?: 0) > 0)
                                <span class="font-medium text-chrome-600">{{ __('Total charged') }}: {{ ValueFormat::money((float) $fuel_charge + RentalOrder::FUEL_SERVICE_FEE) }}</span>
                            @endif
                        </p>
                        @error('fuel_charge') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    {{-- Extra charge: an extra day, a fee, anything — VAT applies like the rental. --}}
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Extra charge (BHD)') }}</label>
                            <input type="number" step="0.001" min="0" wire:model.live="extra_charge" class="o-input w-full" placeholder="0.000">
                            @error('extra_charge') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('What for?') }}</label>
                            <input type="text" wire:model="extra_charge_note" maxlength="255" class="o-input w-full" placeholder="{{ __('e.g. extra day') }}">
                            @error('extra_charge_note') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    @if ((float) ($extra_charge ?: 0) > 0)
                        <p class="-mt-1 text-xs text-chrome-400">{{ __('VAT applies — added to the order total.') }}</p>
                    @endif
                    <label class="inline-flex items-center gap-2 text-sm text-chrome-700">
                        <input type="checkbox" wire:model.live="has_damage" class="rounded border-chrome-300 text-primary-600">
                        {{ __('Customer caused damage') }}
                    </label>
                    @if ($has_damage)
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Damage notes') }} <span class="text-red-500">*</span></label>
                            <textarea wire:model="damage_notes" rows="2" class="o-input w-full" placeholder="{{ __('What was damaged…') }}"></textarea>
                            @error('damage_notes') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Damage video') }}</label>
                            <x-stream-video-upload target="damage_video_url" :url="$damage_video_url" />
                            <p class="mt-1 text-xs text-chrome-400">{{ __('Records the damage at return. Uploads to Cloudflare; share the link with the team.') }}</p>
                            @error('damage_video_url') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                    @endif
                    {{-- Mandatory return video — the car's condition on return, captured every time. --}}
                    <div class="border-t border-chrome-100 pt-3">
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Return video') }} <span class="text-red-500">*</span></label>
                        <x-stream-video-upload target="return_video_url" :url="$return_video_url" />
                        <p class="mt-1 text-xs text-chrome-400">{{ __('Required. Record the car on return. Uploads to Cloudflare; share the link with the team.') }}</p>
                        @error('return_video_url') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex justify-end gap-2 pt-1">
                        <button type="button" wire:click="closeReturn" class="text-sm text-chrome-500 hover:text-chrome-700">{{ __('Cancel') }}</button>
                        <button type="submit" class="o-btn-primary">{{ __('Confirm & return') }}</button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    {{-- ─────────────── Settle deposit (accountant / super-admin) ─────────────── --}}
    @if ($showDeposit)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4" x-data x-on:keydown.escape.window="$wire.closeDeposit()">
            <div class="absolute inset-0 bg-chrome-900/40" wire:click="closeDeposit"></div>
            <div class="relative w-full max-w-md rounded-xl bg-white p-5 shadow-pop ring-1 ring-chrome-900/5">
                <h3 class="mb-1 text-sm font-semibold text-chrome-800">{{ __('Settle deposit') }}</h3>
                <p class="mb-3 text-xs text-chrome-500">{{ __('Return the :amount deposit, deduct part of it, or keep it all.', ['amount' => ValueFormat::money($savedOrder?->deposit ?? 0)]) }}</p>
                <form wire:submit.prevent="confirmDeposit" class="space-y-3">
                    <div class="space-y-2">
                        @foreach (['refund' => __('Refund in full'), 'deduct' => __('Deduct an amount'), 'forfeit' => __('Non-refundable (keep it all)')] as $val => $label)
                            <label class="flex items-center gap-2 rounded-lg border border-chrome-200 px-3 py-2 text-sm text-chrome-700 has-[:checked]:border-primary-400 has-[:checked]:bg-primary-50">
                                <input type="radio" wire:model.live="depositOutcome" value="{{ $val }}" class="text-primary-600">
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>

                    @if ($depositOutcome === 'deduct')
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Amount to deduct (BHD)') }} <span class="text-red-500">*</span></label>
                            <input type="number" step="0.001" min="0" max="{{ $savedOrder?->deposit ?? 0 }}" wire:model.live="deposit_deducted" class="o-input w-full" placeholder="0.000">
                            @if ((float) ($deposit_deducted ?: 0) > 0)
                                <p class="mt-1 text-xs text-chrome-400">{{ __('Refunding') }} <span class="font-medium text-emerald-700">{{ ValueFormat::money(max(0, ($savedOrder?->deposit ?? 0) - (float) ($deposit_deducted ?: 0))) }}</span></p>
                            @endif
                            @error('deposit_deducted') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                    @endif

                    @if ($depositOutcome !== 'refund')
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Reason') }} <span class="text-red-500">*</span></label>
                            <textarea wire:model="deposit_reason" rows="2" class="o-input w-full" placeholder="{{ __('Why is the deposit being deducted / kept…') }}"></textarea>
                            @error('deposit_reason') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div x-data="{
                                 busy: false, error: '', previews: [],
                                 async upload(e) {
                                     this.busy = true; this.error = '';
                                     for (const file of Array.from(e.target.files)) {
                                         const data = new FormData(); data.append('file', file); data.append('bucket', 'rental_deposits');
                                         try {
                                             const r = await fetch(@js(route('form.upload-image')), { method: 'POST', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' }, body: data, credentials: 'same-origin' });
                                             if (!r.ok) { const j = await r.json().catch(() => ({})); this.error = (j.errors && j.errors.file && j.errors.file[0]) || j.message || @js(__('Upload failed.')); continue; }
                                             const j = await r.json(); this.previews.push(j.url); await $wire.call('addDepositPhoto', j.path);
                                         } catch (err) { this.error = err.message || @js(__('Upload failed.')); }
                                     }
                                     e.target.value = ''; this.busy = false;
                                 },
                             }">
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Photos') }}</label>
                            <input type="file" multiple accept="image/*" @change="upload($event)" class="block w-full text-sm text-chrome-600 file:mr-3 file:rounded-lg file:border-0 file:bg-chrome-100 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-chrome-700">
                            <p class="mt-1 text-xs text-chrome-400">{{ __('Attach photos of the damage / reason (recommended).') }}</p>
                            <p x-show="busy" class="mt-1 text-xs text-chrome-400">{{ __('Uploading…') }}</p>
                            <p x-show="error" x-text="error" class="mt-1 text-xs text-red-600"></p>
                            <div class="mt-2 flex flex-wrap gap-2">
                                <template x-for="u in previews" :key="u">
                                    <img :src="u" class="size-14 rounded-lg object-cover ring-1 ring-chrome-900/10" alt="">
                                </template>
                            </div>
                        </div>
                    @endif

                    <div class="flex justify-end gap-2 pt-1">
                        <button type="button" wire:click="closeDeposit" class="text-sm text-chrome-500 hover:text-chrome-700">{{ __('Cancel') }}</button>
                        <button type="submit" class="o-btn-primary">{{ __('Confirm') }}</button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    @script
    <script>
        // On a failed save, jump to the first missing/invalid field and focus it.
        $wire.on('order-scroll-to-error', () => {
            requestAnimationFrame(() => {
                const msg = document.querySelector('.text-red-600');
                if (! msg) return;
                msg.scrollIntoView({ behavior: 'smooth', block: 'center' });
                const field = msg.closest('div')?.querySelector('input, select, textarea');
                if (field) field.focus({ preventScroll: true });
            });
        });
    </script>
    @endscript
</div>
