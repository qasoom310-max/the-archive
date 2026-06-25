{{-- Inline "New customer" modal — shared by the Rental order form and the
     Limousine booking form (both write to the one shared customer list). The
     host component provides $addingCustomer, $newCustomer, saveCustomer() and
     closeCustomerModal(). --}}
@if ($addingCustomer)
    <div class="fixed inset-0 z-50 flex items-center justify-center p-4"
        x-data x-on:keydown.escape.window="$wire.closeCustomerModal()"
        x-init="$nextTick(() => $refs.customerName && $refs.customerName.focus())">
        <div class="absolute inset-0 bg-chrome-900/40" wire:click="closeCustomerModal"></div>
        <div class="relative w-full max-w-md rounded-xl bg-white p-5 shadow-pop ring-1 ring-chrome-900/5">
            <h3 class="mb-3 text-sm font-semibold text-chrome-800">{{ __('New customer') }}</h3>
            <form wire:submit.prevent="saveCustomer" class="space-y-3">
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Name') }} <span class="text-red-500">*</span></label>
                    <input type="text" wire:model="newCustomer.name" x-ref="customerName" class="o-input w-full">
                    @error('newCustomer.name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Phone') }}</label>
                        <input type="text" wire:model="newCustomer.phone" class="o-input w-full">
                        @error('newCustomer.phone') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Email') }}</label>
                        <input type="email" wire:model="newCustomer.email" class="o-input w-full">
                        @error('newCustomer.email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('CPR / ID') }}</label>
                        <input type="text" wire:model="newCustomer.cpr" class="o-input w-full">
                        @error('newCustomer.cpr') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Licence no.') }}</label>
                        <input type="text" wire:model="newCustomer.license_no" class="o-input w-full">
                        @error('newCustomer.license_no') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
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
