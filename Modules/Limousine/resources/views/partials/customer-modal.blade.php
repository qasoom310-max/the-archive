{{-- Inline "New customer" modal for the Limousine booking form. Deliberately
     minimal — name, phone, email and whether the customer is an individual or a
     company. Email is optional: many walk-in / phone bookings never carry one,
     and pax/contact details are collected on the booking itself anyway. Writes
     to the shared transport-customer store. The host component provides
     $addingCustomer, $newCustomer, saveCustomer() and closeCustomerModal(). --}}
@if ($addingCustomer)
    <div class="fixed inset-0 z-50 flex items-center justify-center p-4"
        x-data x-on:keydown.escape.window="$wire.closeCustomerModal()"
        x-init="$nextTick(() => $refs.customerName && $refs.customerName.focus())">
        <div class="absolute inset-0 bg-chrome-900/40" wire:click="closeCustomerModal"></div>
        <div class="relative max-h-[90vh] w-full max-w-md overflow-y-auto rounded-xl bg-white p-5 shadow-pop ring-1 ring-chrome-900/5">
            <h3 class="mb-3 text-sm font-semibold text-chrome-800">{{ __('New customer') }}</h3>
            <form wire:submit.prevent="saveCustomer" class="space-y-3">
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Name') }} <span class="text-red-500">*</span></label>
                    <input type="text" wire:model="newCustomer.name" x-ref="customerName" class="o-input w-full">
                    @error('newCustomer.name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Customer type') }} <span class="text-red-500">*</span></label>
                    <div class="flex gap-2">
                        @foreach (['individual' => __('Individual'), 'company' => __('Company')] as $val => $label)
                            {{-- `relative` anchors the sr-only input here; without it, focusing
                                 the hidden radio scrolls the page — worse inside a modal. --}}
                            <label class="relative flex-1 cursor-pointer" wire:key="ct-{{ $val }}">
                                <input type="radio" wire:model="newCustomer.type" value="{{ $val }}" class="peer sr-only">
                                <span class="block rounded-lg border border-chrome-200 px-3 py-1.5 text-center text-sm text-chrome-600 transition hover:bg-chrome-50 peer-checked:border-primary-500 peer-checked:bg-primary-50 peer-checked:font-medium peer-checked:text-primary-700">{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('newCustomer.type') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Phone') }} <span class="text-red-500">*</span></label>
                        <input type="text" wire:model="newCustomer.phone" class="o-input w-full">
                        @error('newCustomer.phone') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Email') }}</label>
                        <input type="email" wire:model="newCustomer.email" class="o-input w-full">
                        @error('newCustomer.email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" wire:click="closeCustomerModal" class="text-sm text-chrome-500 hover:text-chrome-700">{{ __('Cancel') }}</button>
                    <button type="submit" class="o-btn-primary">{{ __('Add customer') }}</button>
                </div>
            </form>
        </div>
    </div>
@endif
