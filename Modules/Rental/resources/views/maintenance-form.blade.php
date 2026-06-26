<div class="mx-auto max-w-3xl p-4 sm:p-6">
    <x-form-breadcrumb :parent="__('Maintenance')" :parent-url="url('/app/rental/maintenance')" :current="$isEditing ? ($reference ?: __('Maintenance')) : __('New record')" />

    {{-- Workflow panel: status moves only through these guarded actions. --}}
    @if ($isEditing)
        @php
            $mBadge = [
                'scheduled' => 'bg-chrome-200 text-chrome-700',
                'in_progress' => 'bg-amber-100 text-amber-700',
                'done' => 'bg-emerald-100 text-emerald-700',
                'cancelled' => 'bg-red-100 text-red-700',
            ][$status] ?? 'bg-chrome-200 text-chrome-700';
            $mLabel = ['scheduled' => __('Scheduled'), 'in_progress' => __('In progress'), 'done' => __('Done'), 'cancelled' => __('Cancelled')][$status] ?? __('Scheduled');
        @endphp
        <div class="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/[0.06]">
            <div class="flex items-center gap-3">
                <span class="text-sm font-semibold text-chrome-800">{{ $reference }}</span>
                <span class="rounded-full px-2.5 py-0.5 text-[11px] font-semibold uppercase tracking-wide {{ $mBadge }}">{{ $mLabel }}</span>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @if ($status === 'scheduled')
                    <button wire:click="startMaintenance" class="o-btn-primary text-sm">{{ __('Start maintenance') }}</button>
                    <button wire:click="cancelMaintenance" wire:confirm="{{ __('Cancel this maintenance?') }}" class="text-sm font-medium text-red-600 hover:underline">{{ __('Cancel') }}</button>
                @elseif ($status === 'in_progress')
                    <button wire:click="completeMaintenance" wire:confirm="{{ __('Mark this maintenance complete and free the car?') }}" class="o-btn-primary text-sm">{{ __('Mark complete') }}</button>
                @elseif ($status === 'done')
                    <span class="text-[11px] font-medium text-emerald-600">{{ __('Completed — car available') }}</span>
                @endif
            </div>
        </div>
    @endif

    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/[0.06]">
        <h2 class="mb-4 text-sm font-semibold text-chrome-800">{{ __('Maintenance record') }}</h2>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Car') }} *</label>
                <select wire:model="vehicle_id" class="o-input w-full" @disabled($isEditing && $status !== 'scheduled')>
                    <option value="">{{ __('— Select —') }}</option>
                    @foreach ($vehicles as $v)
                        <option value="{{ $v->id }}">{{ $v->displayName() }} ({{ __(ucfirst($v->status)) }})</option>
                    @endforeach
                </select>
                @error('vehicle_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                @if ($isEditing && $status !== 'scheduled')
                    <p class="mt-1 text-xs text-chrome-400">{{ __('The car can’t be changed once maintenance has started.') }}</p>
                @endif
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
                <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Cost (BHD)') }}</label>
                <input type="number" step="0.001" min="0" wire:model="cost" class="o-input w-full">
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('KM') }}</label>
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
