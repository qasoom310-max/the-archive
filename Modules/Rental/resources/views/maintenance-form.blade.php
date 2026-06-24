<div class="mx-auto max-w-3xl p-4 sm:p-6">
    <div class="mb-4 flex items-center gap-2 text-sm text-chrome-500">
        <a href="{{ url('/app/rental/maintenance') }}" wire:navigate class="hover:text-primary-700">{{ __('Maintenance') }}</a>
        <span>/</span>
        <span class="font-medium text-chrome-700">{{ $isEditing ? ($reference ?: __('Maintenance')) : __('New record') }}</span>
    </div>

    <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5">
        <h2 class="mb-4 text-sm font-semibold text-chrome-800">{{ __('Maintenance record') }}</h2>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Vehicle') }} *</label>
                <select wire:model="vehicle_id" class="o-input w-full">
                    <option value="">{{ __('— Select —') }}</option>
                    @foreach ($vehicles as $v)
                        <option value="{{ $v->id }}">{{ $v->name }} ({{ __(ucfirst($v->status)) }})</option>
                    @endforeach
                </select>
                @error('vehicle_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Date') }} *</label>
                <input type="date" wire:model="date" class="o-input w-full">
                @error('date') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Type') }}</label>
                <select wire:model="type" class="o-input w-full">
                    @foreach ($typeOptions as $opt)
                        <option value="{{ $opt['value'] }}">{{ __($opt['label']) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Status') }}</label>
                <select wire:model="status" class="o-input w-full">
                    @foreach ($statusOptions as $opt)
                        <option value="{{ $opt['value'] }}">{{ __($opt['label']) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Cost (BHD)') }}</label>
                <input type="number" step="0.001" min="0" wire:model="cost" class="o-input w-full">
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Odometer') }}</label>
                <input type="number" min="0" wire:model="odometer" class="o-input w-full">
            </div>
            <div class="sm:col-span-2">
                <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Description') }}</label>
                <input type="text" wire:model="description" class="o-input w-full" placeholder="{{ __('e.g. front brake pads + oil') }}">
            </div>
            <div class="sm:col-span-2">
                <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Notes') }}</label>
                <textarea wire:model="notes" rows="2" class="o-input w-full"></textarea>
            </div>
        </div>

        <button wire:click="save" class="o-btn-primary mt-4 w-full justify-center">
            <span wire:loading.remove wire:target="save">{{ $isEditing ? __('Save') : __('Create record') }}</span>
            <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
        </button>
    </div>
</div>
