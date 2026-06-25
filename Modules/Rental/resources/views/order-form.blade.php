@php use App\Erp\Views\ValueFormat; @endphp
<div class="mx-auto max-w-5xl p-4 sm:p-6">
    <div class="mb-4 flex items-center gap-2 text-sm text-chrome-500">
        <a href="{{ url('/app/rental/order') }}" wire:navigate class="hover:text-primary-700">{{ __('Orders') }}</a>
        <span>/</span>
        <span class="font-medium text-chrome-700">{{ $isEditing ? ($reference ?: __('Order')) : __('New order') }}</span>
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
        <div class="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/5">
            <div class="flex items-center gap-3">
                <span class="text-sm font-semibold text-chrome-800">{{ $reference }}</span>
                <span class="rounded px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide {{ $stateBadge }}">{{ __(ucfirst($state)) }}</span>
                <span class="rounded px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide {{ $payment_status === 'paid' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' }}">{{ __(ucfirst($payment_status)) }}</span>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @if ($state === 'draft')
                    <button wire:click="startRental" class="o-btn-primary text-sm">{{ __('Start rental') }}</button>
                @elseif ($state === 'active')
                    <button wire:click="closeRental" class="o-btn-primary text-sm">{{ __('Close rental') }}</button>
                @endif

                @if ($state !== 'cancelled')
                    <button wire:click="createInvoice" class="o-btn-ghost text-sm">{{ __('Create invoice') }}</button>
                @endif

                @if ($payment_status === 'unpaid')
                    <button wire:click="markPaid" class="o-btn-ghost text-sm">{{ __('Mark paid') }}</button>
                @else
                    <button wire:click="markUnpaid" class="o-btn-ghost text-sm">{{ __('Mark unpaid') }}</button>
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
    @php use Modules\Rental\Models\RentalOrder; @endphp
    @if ($savedOrder && ($savedOrder->started_at || $savedOrder->returned_at))
        <div class="mb-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
            @if ($savedOrder->started_at)
                <div class="rounded-xl bg-white p-4 text-sm shadow-sm ring-1 ring-chrome-900/5">
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
                <div class="rounded-xl bg-white p-4 text-sm shadow-sm ring-1 ring-chrome-900/5">
                    <h3 class="mb-2 font-semibold text-chrome-800">{{ __('Return') }} <span class="text-xs font-normal text-chrome-400">{{ $savedOrder->returned_at->format('Y-m-d H:i') }}</span></h3>
                    <dl class="space-y-1 text-chrome-600">
                        <div class="flex justify-between"><dt>{{ __('KM') }}</dt><dd class="font-medium text-chrome-800">{{ $savedOrder->return_km !== null ? number_format((float) $savedOrder->return_km) : '—' }}</dd></div>
                        <div class="flex justify-between"><dt>{{ __('Fuel') }}</dt><dd class="font-medium text-chrome-800">{{ RentalOrder::fuelLabel($savedOrder->return_fuel) }}</dd></div>
                        @if ($savedOrder->fuelChargeTotal() > 0)
                            <div class="flex justify-between"><dt>{{ __('Fuel charge') }}</dt><dd class="font-medium text-amber-700">{{ \App\Erp\Views\ValueFormat::money($savedOrder->fuelChargeTotal()) }}</dd></div>
                        @endif
                        <div class="flex justify-between"><dt>{{ __('Damage') }}</dt><dd class="font-medium {{ $savedOrder->has_damage ? 'text-red-600' : 'text-emerald-600' }}">{{ $savedOrder->has_damage ? __('Yes') : __('No') }}</dd></div>
                        @if ($savedOrder->damage_notes)<div><dt class="text-chrome-400">{{ __('Damage notes') }}</dt><dd class="text-chrome-700">{{ $savedOrder->damage_notes }}</dd></div>@endif
                        @if ($savedOrder->damage_video_url)<a href="{{ $savedOrder->damage_video_url }}" target="_blank" rel="noopener" class="inline-block text-primary-700 hover:underline">{{ __('View damage video') }} ↗</a>@endif
                    </dl>
                </div>
            @endif
        </div>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        {{-- Form --}}
        <div class="space-y-4 lg:col-span-2">
            <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5">
                <h2 class="mb-4 text-sm font-semibold text-chrome-800">{{ __('Rental details') }}</h2>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    {{-- Order number + date --}}
                    <div>
                        <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Order number') }}</label>
                        <input type="text" value="{{ $reference ?: __('Auto') }}" class="o-input w-full bg-chrome-50 text-chrome-500" disabled>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Date') }}</label>
                        <input type="date" wire:model="order_date" class="o-input w-full">
                    </div>

                    {{-- Customer + phone --}}
                    <div>
                        <div class="mb-1 flex items-center justify-between gap-2">
                            <label class="block text-sm font-medium text-chrome-700">{{ __('Customer') }} *</label>
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
                        <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Phone') }}</label>
                        <input type="text" wire:model="phone" placeholder="{{ __('Customer phone number') }}" class="o-input w-full">
                    </div>

                    {{-- Additional driver --}}
                    <div>
                        <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Additional driver') }}</label>
                        <input type="text" wire:model="additional_driver" placeholder="{{ __('Additional driver') }}" class="o-input w-full">
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Licence number') }}</label>
                        <input type="text" wire:model="additional_driver_license" placeholder="{{ __("Additional driver's licence number") }}" class="o-input w-full">
                    </div>

                    {{-- Branch + assigned driver --}}
                    <div>
                        <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Branch') }}</label>
                        <select wire:model="branch_id" class="o-input w-full">
                            <option value="">{{ __('— Select —') }}</option>
                            @foreach ($branches as $b)
                                <option value="{{ $b->id }}">{{ $b->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Delivery') }}</label>
                        <label class="mt-2 inline-flex items-center gap-2 text-sm text-chrome-700">
                            <input type="checkbox" wire:model.live="delivery" class="rounded border-chrome-300 text-primary-600">
                            {{ __('Deliver the car') }}
                            <span class="text-chrome-400">(+ {{ \App\Erp\Views\ValueFormat::money(\Modules\Rental\Models\RentalOrder::DELIVERY_FEE) }})</span>
                        </label>
                        @if ($delivery)
                            <input type="text" wire:model="delivery_location" class="o-input mt-2 w-full" placeholder="{{ __('Delivery location') }}">
                            @error('delivery_location') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        @endif
                    </div>

                    {{-- Vehicle + mileage --}}
                    <div>
                        <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Vehicle') }} *</label>
                        <select wire:model.live="vehicle_id" class="o-input w-full">
                            <option value="">{{ __('— Select —') }}</option>
                            @foreach ($vehicles as $v)
                                <option value="{{ $v->id }}">{{ $v->displayName() }} ({{ __(ucfirst($v->status)) }})</option>
                            @endforeach
                        </select>
                        @error('vehicle_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('KM') }}</label>
                        <input type="number" min="0" wire:model="pickup_mileage" placeholder="{{ __('Current KM') }}" class="o-input w-full">
                    </div>
                </div>

                {{-- Selected vehicle read-out --}}
                @if ($selectedVehicle)
                    <div class="mt-4 grid grid-cols-2 gap-x-4 gap-y-2 rounded-lg bg-chrome-50 p-3 text-xs sm:grid-cols-4">
                        @php
                            $readout = [
                                __('Reservation status') => __(ucfirst($selectedVehicle->status)),
                                __('Vehicle type') => $selectedVehicle->category ? __(ucfirst($selectedVehicle->category)) : '—',
                                __('Fuel type') => $selectedVehicle->fuel_type ? __(ucfirst($selectedVehicle->fuel_type)) : '—',
                                __('Year') => $selectedVehicle->year ?: '—',
                                __('Colour') => $selectedVehicle->color ?: '—',
                                __('KM') => $selectedVehicle->odometer !== null ? number_format((float) $selectedVehicle->odometer) : '—',
                                __('Next maint. date') => $selectedVehicle->next_maintenance_date?->format('Y-m-d') ?? '—',
                                __('Next maint. KM') => $selectedVehicle->next_maintenance_mileage !== null ? number_format((float) $selectedVehicle->next_maintenance_mileage) : '—',
                            ];
                        @endphp
                        @foreach ($readout as $label => $value)
                            <div>
                                <div class="uppercase tracking-wide text-chrome-400">{{ $label }}</div>
                                <div class="font-medium text-chrome-800">{{ $value }}</div>
                            </div>
                        @endforeach
                    </div>
                @endif

                {{-- Hiring period + time --}}
                <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Pick-up date') }} *</label>
                        <input type="date" wire:model.live="start_date" class="o-input w-full" @unless ($canBackdate) min="{{ now()->toDateString() }}" @endunless>
                        @error('start_date') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Return date') }} *</label>
                        <input type="date" wire:model.live="end_date" class="o-input w-full" @if ($start_date) min="{{ $start_date }}" @endif>
                        @error('end_date') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Hired time') }}</label>
                        <input type="time" wire:model="hired_time" class="o-input w-full">
                    </div>
                </div>

                {{-- Rate --}}
                <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Rate type') }} *</label>
                        <select wire:model.live="rate_type" class="o-input w-full">
                            <option value="daily">{{ __('Daily') }}</option>
                            <option value="weekly">{{ __('Weekly') }}</option>
                            <option value="monthly">{{ __('Monthly') }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Rate (BHD)') }} *</label>
                        <input type="number" step="0.001" min="0" wire:model.live="rate" class="o-input w-full">
                        @error('rate') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('VAT') }} %</label>
                        <input type="number" step="0.1" min="0" max="100" wire:model.live="vat_rate" class="o-input w-full">
                    </div>
                </div>

                {{-- Charges --}}
                <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Discount (BHD)') }}</label>
                        <input type="number" step="0.001" min="0" wire:model.live="discount" class="o-input w-full">
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Advance amount (BHD)') }}</label>
                        <input type="number" step="0.001" min="0" wire:model.live="advance_amount" class="o-input w-full">
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Deposit (BHD)') }}</label>
                        <input type="number" step="0.001" min="0" wire:model="deposit" class="o-input w-full">
                    </div>
                </div>

                {{-- Payment type --}}
                <div class="mt-4">
                    <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Payment type') }}</label>
                    <div class="flex flex-wrap gap-4">
                        @foreach ($paymentTypes as $pt)
                            <label class="inline-flex items-center gap-1.5 text-sm text-chrome-700">
                                <input type="radio" wire:model="payment_type" value="{{ $pt['value'] }}" class="text-primary-600">
                                {{ __($pt['label']) }}
                            </label>
                        @endforeach
                    </div>
                </div>

                {{-- Comments --}}
                <div class="mt-4">
                    <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Comments') }}</label>
                    <textarea wire:model="notes" rows="2" class="o-input w-full"></textarea>
                </div>

                {{-- Documents: CPR / ID and driving licence --}}
                <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('CPR image') }}</label>
                        <input type="file" wire:model="cprPhoto" accept="image/*" class="block w-full text-sm text-chrome-600 file:mr-3 file:rounded-md file:border-0 file:bg-chrome-100 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-chrome-700 hover:file:bg-chrome-200">
                        <div wire:loading wire:target="cprPhoto" class="mt-1 text-xs text-chrome-400">{{ __('Uploading…') }}</div>
                        @error('cprPhoto') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        @if ($cprPhoto)
                            <img src="{{ $cprPhoto->temporaryUrl() }}" alt="" class="mt-2 h-24 rounded-lg object-cover ring-1 ring-chrome-200">
                        @elseif ($existingCprImage)
                            <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($existingCprImage) }}" alt="" class="mt-2 h-24 rounded-lg object-cover ring-1 ring-chrome-200">
                        @endif
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Licence image') }}</label>
                        <input type="file" wire:model="licensePhoto" accept="image/*" class="block w-full text-sm text-chrome-600 file:mr-3 file:rounded-md file:border-0 file:bg-chrome-100 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-chrome-700 hover:file:bg-chrome-200">
                        <div wire:loading wire:target="licensePhoto" class="mt-1 text-xs text-chrome-400">{{ __('Uploading…') }}</div>
                        @error('licensePhoto') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        @if ($licensePhoto)
                            <img src="{{ $licensePhoto->temporaryUrl() }}" alt="" class="mt-2 h-24 rounded-lg object-cover ring-1 ring-chrome-200">
                        @elseif ($existingLicenseImage)
                            <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($existingLicenseImage) }}" alt="" class="mt-2 h-24 rounded-lg object-cover ring-1 ring-chrome-200">
                        @endif
                    </div>
                </div>
            </div>
        </div>

        {{-- Live summary --}}
        <div class="space-y-4">
            <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5">
                <h2 class="mb-3 text-sm font-semibold text-chrome-800">{{ __('Summary') }}</h2>
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-chrome-500">{{ __('No. of days') }}</dt><dd class="font-medium text-chrome-800">{{ $previewDays }} {{ __('days') }}</dd></div>
                    <div class="flex justify-between"><dt class="text-chrome-500">{{ __('Billable units') }}</dt><dd class="font-medium text-chrome-800">{{ $previewUnits }}</dd></div>
                    <div class="flex justify-between"><dt class="text-chrome-500">{{ __('Amount') }}</dt><dd class="font-medium text-chrome-800">{{ ValueFormat::money($previewSubtotal) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-chrome-500">{{ __('Discount') }}</dt><dd class="text-chrome-600">− {{ ValueFormat::money((float) ($discount === '' ? '0' : $discount)) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-chrome-500">{{ __('VAT') }}</dt><dd class="text-chrome-600">+ {{ ValueFormat::money($previewVat) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-chrome-500">{{ __('Delivery charges') }}</dt><dd class="text-chrome-600">+ {{ ValueFormat::money($previewDelivery) }}</dd></div>
                    <div class="border-t border-chrome-100 pt-2 flex justify-between text-base"><dt class="font-semibold text-chrome-700">{{ __('Net total') }}</dt><dd class="font-bold text-chrome-900">{{ ValueFormat::money($previewTotal) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-chrome-500">{{ __('Advance') }}</dt><dd class="text-chrome-600">− {{ ValueFormat::money((float) ($advance_amount === '' ? '0' : $advance_amount)) }}</dd></div>
                    <div class="flex justify-between text-base"><dt class="font-semibold text-chrome-700">{{ __('Balance') }}</dt><dd class="font-bold text-primary-700">{{ ValueFormat::money($previewBalance) }}</dd></div>
                    <div class="flex justify-between text-xs"><dt class="text-chrome-400">{{ __('Deposit (refundable)') }}</dt><dd class="text-chrome-500">{{ ValueFormat::money((float) ($deposit === '' ? '0' : $deposit)) }}</dd></div>
                </dl>

                <button wire:click="save" class="o-btn-primary mt-4 w-full justify-center">
                    <span wire:loading.remove wire:target="save">{{ $isEditing ? __('Save order') : __('Add record') }}</span>
                    <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
                </button>
                <a href="{{ url('/app/rental/order') }}" wire:navigate class="mt-2 block text-center text-sm text-chrome-500 hover:text-chrome-700">{{ __('Cancel') }}</a>
            </div>
        </div>
    </div>

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
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Video link') }}</label>
                        <input type="url" wire:model="handover_video_url" class="o-input w-full" placeholder="https://…">
                        <p class="mt-1 text-xs text-chrome-400">{{ __('Paste a cloud link (Drive, Photos, Dropbox…). The video isn’t stored on the server.') }}</p>
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
                <p class="mb-3 text-xs text-chrome-500">{{ __('Record the car’s condition on return. The KM updates the vehicle.') }}</p>
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
                            {{ __('If returned with less fuel, enter the refuel cost. A :fee service fee is added.', ['fee' => \App\Erp\Views\ValueFormat::money(RentalOrder::FUEL_SERVICE_FEE)]) }}
                            @if ((float) ($fuel_charge ?: 0) > 0)
                                <span class="font-medium text-chrome-600">{{ __('Total charged') }}: {{ \App\Erp\Views\ValueFormat::money((float) $fuel_charge + RentalOrder::FUEL_SERVICE_FEE) }}</span>
                            @endif
                        </p>
                        @error('fuel_charge') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
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
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Damage video link') }}</label>
                            <input type="url" wire:model="damage_video_url" class="o-input w-full" placeholder="https://…">
                            <p class="mt-1 text-xs text-chrome-400">{{ __('Optional — a cloud link to the damage clip.') }}</p>
                            @error('damage_video_url') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                    @endif
                    <div class="flex justify-end gap-2 pt-1">
                        <button type="button" wire:click="closeReturn" class="text-sm text-chrome-500 hover:text-chrome-700">{{ __('Cancel') }}</button>
                        <button type="submit" class="o-btn-primary">{{ __('Confirm & return') }}</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
