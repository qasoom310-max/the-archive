@php use Modules\Rental\Models\RentalReplacement; @endphp
<div class="mx-auto max-w-3xl p-4 sm:p-6">
    <x-form-breadcrumb :parent="__('Car replacements')" :parent-url="url('/app/rental/replacement')" :current="$isEditing ? ($reference ?: __('Replacement')) : __('New replacement')" />

    {{-- Blocked: a replacement must start from a live, on-road order. --}}
    @if ($blocked && ! $isEditing)
        <div class="rounded-2xl border border-amber-200 bg-amber-50/70 p-6 text-center shadow-sm">
            <h2 class="text-sm font-semibold text-amber-900">{{ __('Start a replacement from a rental') }}</h2>
            <p class="mx-auto mt-2 max-w-md text-sm text-amber-800">{{ __('A car replacement happens on an active rental. Open the customer\'s on-road order and use “Replace car”.') }}</p>
            <a href="{{ url('/app/rental/order') }}" wire:navigate class="o-btn-primary mt-4 inline-flex">{{ __('Go to orders') }}</a>
        </div>
    @elseif ($isEditing)
        {{-- Existing replacement: read-only summary + close. --}}
        <div class="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/[0.06]">
            <div class="flex items-center gap-3">
                <span class="text-sm font-semibold text-chrome-800">{{ $reference }}</span>
                <span class="rounded px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide {{ $status === 'active' ? 'bg-sky-100 text-sky-700' : 'bg-emerald-100 text-emerald-700' }}">{{ __(ucfirst($status)) }}</span>
                @if ($order)
                    <a href="{{ url('/app/rental/order/' . $order->id) }}" wire:navigate class="text-xs text-primary-700 hover:underline">{{ $order->reference }}</a>
                @endif
            </div>
            @if ($status === 'active')
                <button wire:click="close" wire:confirm="{{ __('Close this replacement? The original car returns to the fleet.') }}" class="o-btn-primary text-sm">{{ __('Close replacement') }}</button>
            @endif
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/[0.06]">
                <h3 class="mb-2 text-sm font-semibold text-chrome-800">{{ __('Original car — in') }}</h3>
                <dl class="space-y-1 text-sm text-chrome-600">
                    <div class="flex justify-between"><dt>{{ __('Car') }}</dt><dd class="font-medium text-chrome-800">{{ $originalVehicle?->displayName() ?? '—' }}</dd></div>
                    <div class="flex justify-between"><dt>{{ __('KM') }}</dt><dd>{{ $original_return_km !== null && $original_return_km !== '' ? number_format((float) $original_return_km) : '—' }}</dd></div>
                    <div class="flex justify-between"><dt>{{ __('Fuel') }}</dt><dd>{{ \Modules\Rental\Models\RentalOrder::fuelLabel($original_return_fuel ?: null) }}</dd></div>
                    <div class="flex justify-between"><dt>{{ __('Reason') }}</dt><dd>{{ __(RentalReplacement::reasonTypeLabel($reason_type)) }}</dd></div>
                </dl>
                @if ($original_condition_notes !== '')<p class="mt-2 text-xs text-chrome-500">{{ $original_condition_notes }}</p>@endif
            </div>
            <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/[0.06]">
                <h3 class="mb-2 text-sm font-semibold text-chrome-800">{{ __('Replacement car — out') }}</h3>
                <dl class="space-y-1 text-sm text-chrome-600">
                    <div class="flex justify-between"><dt>{{ __('Car') }}</dt><dd class="font-medium text-chrome-800">{{ $replacementVehicle?->displayName() ?? '—' }}</dd></div>
                    <div class="flex justify-between"><dt>{{ __('KM') }}</dt><dd>{{ $replacement_handover_km !== null && $replacement_handover_km !== '' ? number_format((float) $replacement_handover_km) : '—' }}</dd></div>
                    <div class="flex justify-between"><dt>{{ __('Fuel') }}</dt><dd>{{ \Modules\Rental\Models\RentalOrder::fuelLabel($replacement_handover_fuel ?: null) }}</dd></div>
                </dl>
                @if ($replacement_condition_notes !== '')<p class="mt-2 text-xs text-chrome-500">{{ $replacement_condition_notes }}</p>@endif
            </div>
        </div>

        @if ($order)
            <div class="mt-6"><x-activity-trail :subject="$order" :title="__('Order activity')" /></div>
        @endif
    @else
        {{-- New replacement on a live order. --}}
        {{-- Locked context: order / customer / original car. --}}
        <div class="mb-5 rounded-2xl bg-chrome-50 p-4 ring-1 ring-chrome-900/[0.06]">
            <div class="grid gap-3 text-sm sm:grid-cols-3">
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-chrome-400">{{ __('Order') }}</p>
                    <p class="font-semibold text-chrome-800">{{ $order?->reference ?? '—' }}</p>
                </div>
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-chrome-400">{{ __('Customer') }}</p>
                    <p class="font-semibold text-chrome-800">{{ $order?->customer?->name ?? '—' }}</p>
                </div>
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-chrome-400">{{ __('Original car') }}</p>
                    <p class="font-semibold text-chrome-800">{{ $originalVehicle?->displayName() ?? '—' }}</p>
                </div>
            </div>
        </div>

        <div class="space-y-5">
            {{-- Why + which car --}}
            <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/[0.06]">
                <h2 class="mb-4 text-sm font-semibold text-chrome-800">{{ __('Replacement') }}</h2>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Reason') }} *</label>
                        <select wire:model.live="reason_type" class="o-input w-full">
                            @foreach ($reasonOptions as $opt)
                                <option value="{{ $opt['value'] }}">{{ __($opt['label']) }}</option>
                            @endforeach
                        </select>
                        @error('reason_type') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        <p class="mt-1 text-xs text-chrome-400">
                            @if (in_array($reason_type, [RentalReplacement::REASON_BREAKDOWN, RentalReplacement::REASON_ACCIDENT, RentalReplacement::REASON_SERVICE], true))
                                {{ __('The original car will go to maintenance.') }}
                            @else
                                {{ __('The original car will return to the fleet.') }}
                            @endif
                        </p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Date') }} *</label>
                        <input type="date" wire:model="date" class="o-input w-full">
                        @error('date') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Replacement car') }} *</label>
                        <select wire:model="replacement_vehicle_id" class="o-input w-full">
                            <option value="">{{ __('— Select an available car —') }}</option>
                            @foreach ($availableVehicles as $v)
                                <option value="{{ $v->id }}">{{ $v->displayName() }}@if ($v->status !== 'available') · {{ __(ucfirst($v->status)) }}@endif@if ($v->needsRenewal()) · {{ __('no valid papers') }}@endif</option>
                            @endforeach
                        </select>
                        @error('replacement_vehicle_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        @if ($availableVehicles->isEmpty())
                            <p class="mt-1 text-xs text-amber-600">
                                {{ __('No car is free to swap in right now.') }}
                                @unless ($isSuperAdmin) {{ __('Cars with missing or expired papers are hidden — a super-admin can override.') }} @endunless
                            </p>
                        @elseif ($isSuperAdmin)
                            <p class="mt-1 text-xs text-chrome-400">{{ __('Cars in maintenance / reserved / without papers are shown for an urgent swap only.') }}</p>
                        @endif
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Reason note') }}</label>
                        <input type="text" wire:model="reason" class="o-input w-full" placeholder="{{ __('e.g. engine warning light on the highway') }}">
                    </div>
                </div>
            </div>

            {{-- Handover capture: original in, replacement out --}}
            <div class="grid gap-5 sm:grid-cols-2">
                <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/[0.06]">
                    <h3 class="mb-3 text-sm font-semibold text-chrome-800">{{ __('Original car — in') }}</h3>
                    <div class="space-y-3">
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Return KM') }}</label>
                            <input type="number" min="0" wire:model="original_return_km" class="o-input w-full">
                            @error('original_return_km') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Fuel') }}</label>
                            <select wire:model="original_return_fuel" class="o-input w-full">
                                <option value="">{{ __('—') }}</option>
                                @foreach ($fuelOptions as $opt)
                                    <option value="{{ $opt['value'] }}">{{ __($opt['label']) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Condition / damage') }}</label>
                            <textarea wire:model="original_condition_notes" rows="2" class="o-input w-full"></textarea>
                        </div>
                    </div>
                </div>
                <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/[0.06]">
                    <h3 class="mb-3 text-sm font-semibold text-chrome-800">{{ __('Replacement car — out') }}</h3>
                    <div class="space-y-3">
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Handover KM') }}</label>
                            <input type="number" min="0" wire:model="replacement_handover_km" class="o-input w-full">
                            @error('replacement_handover_km') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Fuel') }}</label>
                            <select wire:model="replacement_handover_fuel" class="o-input w-full">
                                <option value="">{{ __('—') }}</option>
                                @foreach ($fuelOptions as $opt)
                                    <option value="{{ $opt['value'] }}">{{ __($opt['label']) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Condition') }}</label>
                            <textarea wire:model="replacement_condition_notes" rows="2" class="o-input w-full"></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/[0.06]">
                <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Notes') }}</label>
                <textarea wire:model="notes" rows="2" class="o-input w-full"></textarea>
            </div>

            <button wire:click="save" class="o-btn-primary w-full justify-center">
                <span wire:loading.remove wire:target="save">{{ __('Confirm replacement') }}</span>
                <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
            </button>
        </div>
    @endif
</div>
