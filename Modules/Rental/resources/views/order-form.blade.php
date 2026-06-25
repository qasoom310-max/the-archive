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

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        {{-- Form --}}
        <div class="space-y-4 lg:col-span-2">
            <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5">
                <h2 class="mb-4 text-sm font-semibold text-chrome-800">{{ __('Rental details') }}</h2>

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
                        <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Vehicle') }} *</label>
                        <select wire:model.live="vehicle_id" class="o-input w-full">
                            <option value="">{{ __('— Select —') }}</option>
                            @foreach ($vehicles as $v)
                                <option value="{{ $v->id }}">{{ $v->name }} ({{ __(ucfirst($v->status)) }})</option>
                            @endforeach
                        </select>
                        @error('vehicle_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Driver') }}</label>
                        <select wire:model="driver_id" class="o-input w-full">
                            <option value="">{{ __('No driver') }}</option>
                            @foreach ($drivers as $d)
                                <option value="{{ $d->id }}">{{ $d->name }}</option>
                            @endforeach
                        </select>
                    </div>

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
                        <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Pick-up date') }} *</label>
                        <input type="date" wire:model.live="start_date" class="o-input w-full">
                        @error('start_date') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Return date') }} *</label>
                        <input type="date" wire:model.live="end_date" class="o-input w-full">
                        @error('end_date') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Rate type') }}</label>
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
                        <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Discount (BHD)') }}</label>
                        <input type="number" step="0.001" min="0" wire:model.live="discount" class="o-input w-full">
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Deposit (BHD)') }}</label>
                        <input type="number" step="0.001" min="0" wire:model="deposit" class="o-input w-full">
                    </div>
                </div>

                <div class="mt-4">
                    <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Notes') }}</label>
                    <textarea wire:model="notes" rows="2" class="o-input w-full"></textarea>
                </div>
            </div>
        </div>

        {{-- Live summary --}}
        <div class="space-y-4">
            <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5">
                <h2 class="mb-3 text-sm font-semibold text-chrome-800">{{ __('Summary') }}</h2>
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-chrome-500">{{ __('Duration') }}</dt><dd class="font-medium text-chrome-800">{{ $previewDays }} {{ __('days') }}</dd></div>
                    <div class="flex justify-between"><dt class="text-chrome-500">{{ __('Billable units') }}</dt><dd class="font-medium text-chrome-800">{{ $previewUnits }}</dd></div>
                    <div class="flex justify-between"><dt class="text-chrome-500">{{ __('Subtotal') }}</dt><dd class="font-medium text-chrome-800">{{ \App\Erp\Views\ValueFormat::money($previewSubtotal) }}</dd></div>
                    <div class="border-t border-chrome-100 pt-2 flex justify-between text-base"><dt class="font-semibold text-chrome-700">{{ __('Total') }}</dt><dd class="font-bold text-chrome-900">{{ \App\Erp\Views\ValueFormat::money($previewTotal) }}</dd></div>
                    <div class="flex justify-between text-xs"><dt class="text-chrome-400">{{ __('Deposit (refundable)') }}</dt><dd class="text-chrome-500">{{ \App\Erp\Views\ValueFormat::money((float) ($deposit === '' ? '0' : $deposit)) }}</dd></div>
                </dl>

                <button wire:click="save" class="o-btn-primary mt-4 w-full justify-center">
                    <span wire:loading.remove wire:target="save">{{ $isEditing ? __('Save order') : __('Create order') }}</span>
                    <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
                </button>
            </div>
        </div>
    </div>

    @include('rental::partials.customer-modal')
</div>
