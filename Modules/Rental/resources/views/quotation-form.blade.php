<div class="mx-auto max-w-5xl p-4 sm:p-6">
    <x-form-breadcrumb :parent="__('Quotations')" :parent-url="url('/app/rental/quotation')" :current="$isEditing ? ($reference ?: __('Quotation')) : __('New quotation')" />

    @php
        $statusBadge = [
            'draft' => 'bg-chrome-200 text-chrome-700',
            'sent' => 'bg-sky-100 text-sky-700',
            'accepted' => 'bg-emerald-100 text-emerald-700',
            'declined' => 'bg-red-100 text-red-700',
            'converted' => 'bg-violet-100 text-violet-700',
        ][$status] ?? 'bg-chrome-200 text-chrome-700';
    @endphp

    @if ($isEditing)
        <div class="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/[0.06]">
            <div class="flex items-center gap-3">
                <span class="text-sm font-semibold text-chrome-800">{{ $reference }}</span>
                <span class="rounded px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide {{ $statusBadge }}">{{ __(ucfirst($status)) }}</span>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @if ($status === 'draft')
                    <button wire:click="markSent" class="o-btn-ghost text-sm">{{ __('Mark sent') }}</button>
                @endif
                @if (in_array($status, ['draft', 'sent'], true))
                    <button wire:click="markAccepted" class="o-btn-ghost text-sm">{{ __('Mark accepted') }}</button>
                    <button wire:click="markDeclined" class="text-sm font-medium text-red-600 hover:underline">{{ __('Decline') }}</button>
                @endif
                @if ($status !== 'converted')
                    <button wire:click="convert" class="o-btn-primary text-sm">{{ __('Convert to order') }}</button>
                @elseif ($order_id)
                    <a href="{{ url('/app/rental/order/' . $order_id) }}" wire:navigate class="o-btn-ghost text-sm">{{ __('Open order') }}</a>
                @endif
            </div>
        </div>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/[0.06]">
                <h2 class="mb-4 text-sm font-semibold text-chrome-800">{{ __('Quotation details') }}</h2>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Customer') }} *</label>
                        <x-searchable-select wire:model="customer_id" class="o-input w-full"
                            :options="collect($customers)->map(fn ($c) => [
                                'value' => $c->id,
                                'label' => $c->name . ($c->phone ? ' · ' . $c->phone : ''),
                            ])->all()"
                            :search-placeholder="__('Search name or number…')" />
                        @error('customer_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Car') }} *</label>
                        <x-searchable-select wire:model.live="vehicle_id" class="o-input w-full"
                            :options="collect($vehicles)->map(fn ($v) => [
                                'value' => $v->id,
                                'label' => $v->displayName() . ' (' . __(ucfirst($v->status)) . ')',
                            ])->all()"
                            :search-placeholder="__('Search plate or model…')" />
                        @error('vehicle_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Driver') }}</label>
                        <x-searchable-select wire:model="driver_id" class="o-input w-full"
                            :placeholder="__('No driver')"
                            :options="collect($drivers)->map(fn ($d) => ['value' => $d->id, 'label' => $d->name])->all()"
                            :search-placeholder="__('Search driver…')" />
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
                        <x-date-field wire:model.live="start_date" class="o-input w-full" />
                        @error('start_date') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Return date') }} *</label>
                        <x-date-field wire:model.live="end_date" class="o-input w-full" />
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

                    <div>
                        <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Valid until') }}</label>
                        <x-date-field wire:model="valid_until" class="o-input w-full" />
                    </div>
                </div>

                <div class="mt-4">
                    <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Notes') }}</label>
                    <textarea wire:model="notes" rows="2" class="o-input w-full"></textarea>
                </div>
            </div>
        </div>

        <div class="space-y-4">
            <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/[0.06]">
                <h2 class="mb-3 text-sm font-semibold text-chrome-800">{{ __('Summary') }}</h2>
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-chrome-500">{{ __('Duration') }}</dt><dd class="font-medium text-chrome-800">{{ $previewDays }} {{ __('days') }}</dd></div>
                    <div class="flex justify-between"><dt class="text-chrome-500">{{ __('Billable units') }}</dt><dd class="font-medium text-chrome-800">{{ $previewUnits }}</dd></div>
                    <div class="flex justify-between"><dt class="text-chrome-500">{{ __('Subtotal') }}</dt><dd class="font-medium text-chrome-800">{{ \App\Erp\Views\ValueFormat::money($previewSubtotal) }}</dd></div>
                    <div class="border-t border-chrome-100 pt-2 flex justify-between text-base"><dt class="font-semibold text-chrome-700">{{ __('Total') }}</dt><dd class="font-bold text-chrome-900">{{ \App\Erp\Views\ValueFormat::money($previewTotal) }}</dd></div>
                    <div class="flex justify-between text-xs"><dt class="text-chrome-400">{{ __('Deposit (refundable)') }}</dt><dd class="text-chrome-500">{{ \App\Erp\Views\ValueFormat::money((float) ($deposit === '' ? '0' : $deposit)) }}</dd></div>
                </dl>

                <button wire:click="save" class="o-btn-primary mt-4 w-full justify-center">
                    <span wire:loading.remove wire:target="save">{{ $isEditing ? __('Save quotation') : __('Create quotation') }}</span>
                    <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
                </button>
            </div>
        </div>
    </div>
</div>
