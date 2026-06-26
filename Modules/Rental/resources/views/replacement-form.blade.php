<div class="mx-auto max-w-3xl p-4 sm:p-6">
    <x-form-breadcrumb :parent="__('Car replacements')" :parent-url="url('/app/rental/replacement')" :current="$isEditing ? ($reference ?: __('Replacement')) : __('New replacement')" />

    @if ($isEditing)
        <div class="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/[0.06]">
            <div class="flex items-center gap-3">
                <span class="text-sm font-semibold text-chrome-800">{{ $reference }}</span>
                <span class="rounded px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide {{ $status === 'active' ? 'bg-sky-100 text-sky-700' : 'bg-emerald-100 text-emerald-700' }}">{{ __(ucfirst($status)) }}</span>
            </div>
            @if ($status === 'active')
                <button wire:click="close" wire:confirm="{{ __('Close this replacement and free both cars?') }}" class="o-btn-primary text-sm">{{ __('Close replacement') }}</button>
            @endif
        </div>
    @endif

    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/[0.06]">
        <h2 class="mb-4 text-sm font-semibold text-chrome-800">{{ __('Replacement details') }}</h2>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Customer') }}</label>
                <select wire:model="customer_id" class="o-input w-full">
                    <option value="">{{ __('— Select —') }}</option>
                    @foreach ($customers as $c)
                        <option value="{{ $c->id }}">{{ $c->name }}{{ $c->phone ? ' · ' . $c->phone : '' }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Date') }} *</label>
                <input type="date" wire:model="date" class="o-input w-full">
                @error('date') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Original car') }} *</label>
                <select wire:model="original_vehicle_id" class="o-input w-full">
                    <option value="">{{ __('— Select —') }}</option>
                    @foreach ($vehicles as $v)
                        <option value="{{ $v->id }}">{{ $v->displayName() }} ({{ __(ucfirst($v->status)) }})</option>
                    @endforeach
                </select>
                @error('original_vehicle_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Replacement car') }} *</label>
                <select wire:model="replacement_vehicle_id" class="o-input w-full">
                    <option value="">{{ __('— Select —') }}</option>
                    @foreach ($vehicles as $v)
                        <option value="{{ $v->id }}">{{ $v->displayName() }} ({{ __(ucfirst($v->status)) }})</option>
                    @endforeach
                </select>
                @error('replacement_vehicle_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div class="sm:col-span-2">
                <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Reason') }}</label>
                <input type="text" wire:model="reason" class="o-input w-full" placeholder="{{ __('e.g. breakdown, accident, service') }}">
            </div>
            <div class="sm:col-span-2">
                <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Notes') }}</label>
                <textarea wire:model="notes" rows="2" class="o-input w-full"></textarea>
            </div>
        </div>

        <button wire:click="save" class="o-btn-primary mt-4 w-full justify-center">
            <span wire:loading.remove wire:target="save">{{ $isEditing ? __('Save') : __('Create replacement') }}</span>
            <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
        </button>
    </div>
</div>
