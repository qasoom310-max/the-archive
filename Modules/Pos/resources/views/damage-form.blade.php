<div class="mx-auto max-w-2xl p-4 sm:p-6">
    <div class="mb-4 flex items-center gap-2 text-sm text-chrome-500">
        <a href="{{ url('/app/pos') }}" wire:navigate class="hover:text-primary-700">{{ __('Point of Sale') }}</a>
        <span>/</span>
        <a href="{{ url('/app/pos/damage') }}" wire:navigate class="hover:text-primary-700">{{ __('Damage Report') }}</a>
        <span>/</span>
        <span class="font-medium text-chrome-700">{{ __('Log damage') }}</span>
    </div>

    <form wire:submit="save" class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5">
        <h1 class="mb-1 text-lg font-bold text-chrome-900">{{ __('Log damage') }}</h1>
        <p class="mb-4 text-sm text-chrome-500">{{ __('Write off stock lost to breakage, spoilage, spillage, expiry or theft. The item’s on-hand quantity is reduced.') }}</p>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            {{-- Damaged item — one combobox over products, ingredients & condiments. --}}
            <div class="sm:col-span-2">
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Item') }} <span class="text-red-500">*</span></label>
                <select wire:model="itemKey" class="o-input w-full">
                    <option value="">{{ __('Select an item…') }}</option>
                    @foreach ($items->groupBy('group') as $group => $groupItems)
                        <optgroup label="{{ $group }}">
                            @foreach ($groupItems as $item)
                                <option value="{{ $item['key'] }}">
                                    {{ $item['name'] }} — {{ rtrim(rtrim(number_format($item['stock'], 3), '0'), '.') }} {{ __('in stock') }}
                                </option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
                @error('itemKey') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Quantity') }} <span class="text-red-500">*</span></label>
                <input type="number" step="0.001" min="0" wire:model="quantity" class="o-input w-full text-end">
                @error('quantity') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Date') }} <span class="text-red-500">*</span></label>
                <input type="date" wire:model="damagedOn" class="o-input w-full">
                @error('damagedOn') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Reason') }}</label>
                <select wire:model="reason" class="o-input w-full">
                    @foreach ($reasons as $value => $label)
                        <option value="{{ $value }}">{{ __($label) }}</option>
                    @endforeach
                </select>
                @error('reason') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="sm:col-span-2">
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Note') }}</label>
                <textarea wire:model="note" rows="2" class="o-input w-full" placeholder="{{ __('Optional — what happened') }}"></textarea>
                @error('note') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="mt-5 flex items-center justify-end gap-3">
            <a href="{{ url('/app/pos/damage') }}" wire:navigate class="text-sm text-chrome-500 hover:text-chrome-700">{{ __('Cancel') }}</a>
            <button type="submit" class="o-btn-primary">{{ __('Log damage') }}</button>
        </div>
    </form>
</div>
