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
                        <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Driver') }}</label>
                        <x-searchable-select wire:model="driver_id" class="o-input w-full"
                            :placeholder="__('No driver')"
                            :options="collect($drivers)->map(fn ($d) => ['value' => $d->id, 'label' => $d->name])->all()"
                            :search-placeholder="__('Search driver…')" />
                    </div>

                    {{-- Every car on its own line with its price beside it: the
                         first one too, so all the lines read the same. --}}
                    @php
                        $carOptions = collect($vehicles)->map(fn ($v) => [
                            'value' => $v->id,
                            'label' => $v->displayName() . ' (' . __(ucfirst($v->status)) . ')',
                        ])->all();
                    @endphp
                    <div class="sm:col-span-2" data-quote-cars>
                        <div class="mb-1 flex items-end gap-2 text-sm font-medium text-chrome-700">
                            <span class="flex-1">{{ $carCount > 1 ? __('Cars') : __('Car') }} *</span>
                            <span class="w-16 shrink-0">{{ __('Count') }} *</span>
                            <span class="w-24 shrink-0">{{ __('Rate per car (BHD)') }} *</span>
                            <span class="w-6 shrink-0" aria-hidden="true"></span>
                        </div>

                        <div class="flex items-start gap-2">
                            <div class="min-w-0 flex-1">
                                <x-searchable-select wire:model.live="vehicle_id" class="o-input w-full"
                                    :options="$carOptions"
                                    :search-placeholder="__('Search plate or model…')" />
                            </div>
                            <input type="number" step="1" min="1" max="{{ $maxCars }}" wire:model.live.debounce.400ms="quantity"
                                class="o-input w-16 shrink-0" aria-label="{{ __('Count') }}" data-car-count>
                            <input type="number" step="0.001" min="0" wire:model.live.debounce.400ms="rate"
                                class="o-input w-24 shrink-0" aria-label="{{ __('Rate per car (BHD)') }}" data-car-rate>
                            <span class="w-6 shrink-0" aria-hidden="true"></span>
                        </div>
                        @error('vehicle_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        @error('quantity') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        @error('rate') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

                        @foreach ($extraCars as $i => $car)
                            <div wire:key="extra-car-{{ $i }}" class="mt-2" data-extra-car>
                                <div class="flex items-start gap-2">
                                    <div class="min-w-0 flex-1">
                                        <x-searchable-select wire:model.live="extraCars.{{ $i }}.vehicle_id" class="o-input w-full"
                                            :placeholder="__('Car :n', ['n' => $i + 2])"
                                            :options="$carOptions"
                                            :search-placeholder="__('Search plate or model…')" />
                                    </div>
                                    <input type="number" step="1" min="1" max="{{ $maxCars }}" wire:model.live.debounce.400ms="extraCars.{{ $i }}.qty"
                                        class="o-input w-16 shrink-0" aria-label="{{ __('Count') }}" data-car-count>
                                    <input type="number" step="0.001" min="0" wire:model.live.debounce.400ms="extraCars.{{ $i }}.rate"
                                        class="o-input w-24 shrink-0" aria-label="{{ __('Rate per car (BHD)') }}" data-car-rate>
                                    <button type="button" wire:click="removeCar({{ $i }})"
                                        class="mt-2 w-6 shrink-0 rounded p-1 text-chrome-400 hover:bg-red-50 hover:text-red-600"
                                        title="{{ __('Remove this car') }}" aria-label="{{ __('Remove this car') }}" data-remove-car>
                                        <svg class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z"/></svg>
                                    </button>
                                </div>
                                @error('extraCars.' . $i . '.vehicle_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                @error('extraCars.' . $i . '.qty') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                @error('extraCars.' . $i . '.rate') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>
                        @endforeach

                        @if ($carCount < $maxCars)
                            <button type="button" wire:click="addCar" data-add-car
                                class="mt-1.5 inline-flex items-center gap-1 text-xs font-medium text-primary-700 hover:underline">
                                <span aria-hidden="true">+</span> {{ __('Add another car') }}
                            </button>
                        @else
                            <span class="mt-1.5 inline-block text-xs text-chrome-500">{{ __('Up to :max cars on one quotation.', ['max' => $maxCars]) }}</span>
                        @endif
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
                        <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Rate type') }}</label>
                        <select wire:model.live="rate_type" class="o-input w-full">
                            <option value="daily">{{ __('Daily') }}</option>
                            <option value="weekly">{{ __('Weekly') }}</option>
                            <option value="monthly">{{ __('Monthly') }}</option>
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
                    @if ($carCount > 1)
                        <div class="flex justify-between"><dt class="text-chrome-500">{{ __('Cars') }}</dt><dd class="font-medium text-chrome-800">{{ $carCount }}</dd></div>
                    @endif
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
