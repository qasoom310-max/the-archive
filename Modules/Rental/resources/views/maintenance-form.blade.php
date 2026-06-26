<div class="mx-auto max-w-3xl p-4 sm:p-6">
    <x-form-breadcrumb :parent="__('Maintenance')" :parent-url="url('/app/rental/maintenance')" :current="$isEditing ? ($reference ?: __('Maintenance')) : __('New record')" />

    {{-- Work-order workflow: Pending approval → Approved → In progress → Done.
         Status moves ONLY through these guarded actions; approval is a manager's. --}}
    @if ($isEditing)
        @php
            $mBadge = [
                'pending' => 'bg-amber-100 text-amber-700',
                'approved' => 'bg-sky-100 text-sky-700',
                'in_progress' => 'bg-indigo-100 text-indigo-700',
                'done' => 'bg-emerald-100 text-emerald-700',
                'declined' => 'bg-red-100 text-red-700',
                'cancelled' => 'bg-chrome-200 text-chrome-700',
            ][$status] ?? 'bg-amber-100 text-amber-700';
            $mLabel = ['pending' => __('Pending approval'), 'approved' => __('Approved'), 'in_progress' => __('In progress'), 'done' => __('Done'), 'declined' => __('Declined'), 'cancelled' => __('Cancelled')][$status] ?? __('Pending approval');
            $prBadge = ['low' => 'bg-chrome-100 text-chrome-500', 'normal' => 'bg-chrome-100 text-chrome-600', 'high' => 'bg-amber-100 text-amber-700', 'critical' => 'bg-red-100 text-red-700'][$priority] ?? 'bg-chrome-100 text-chrome-600';
        @endphp
        <div class="mb-5 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/[0.06]">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex flex-wrap items-center gap-2.5">
                    <span class="text-sm font-semibold text-chrome-800">{{ $reference }}</span>
                    <span class="rounded-full px-2.5 py-0.5 text-[11px] font-semibold uppercase tracking-wide {{ $mBadge }}">{{ $mLabel }}</span>
                    <span class="rounded-full px-2.5 py-0.5 text-[11px] font-semibold uppercase tracking-wide {{ $prBadge }}">{{ __(ucfirst($priority)) }}</span>
                    @if ($savedRecord?->requestedBy)
                        <span class="text-[11px] text-chrome-400">{{ __('Requested by') }} {{ $savedRecord->requestedBy->name }} · {{ $savedRecord->created_at?->format('Y-m-d') }}</span>
                    @endif
                    @if ($savedRecord?->approvedBy && in_array($status, ['approved', 'in_progress', 'done'], true))
                        <span class="text-[11px] text-chrome-400">· {{ __('Approved by') }} {{ $savedRecord->approvedBy->name }} · {{ $savedRecord->approved_at?->format('Y-m-d') }}</span>
                    @elseif ($savedRecord?->approvedBy && $status === 'declined')
                        <span class="text-[11px] text-red-500">· {{ __('Declined by') }} {{ $savedRecord->approvedBy->name }} · {{ $savedRecord->approved_at?->format('Y-m-d') }}</span>
                    @endif
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    @if ($status === 'pending')
                        @if ($canApprove)
                            <button wire:click="approveMaintenance" class="o-btn-primary text-sm">{{ __('Approve') }}</button>
                            <button wire:click="declineMaintenance" wire:confirm="{{ __('Decline this work order?') }}" class="text-sm font-medium text-red-600 hover:underline">{{ __('Decline') }}</button>
                        @else
                            <span class="text-[11px] font-medium text-amber-600">{{ __('Awaiting manager approval') }}</span>
                        @endif
                        <button wire:click="cancelMaintenance" wire:confirm="{{ __('Cancel this work order?') }}" class="text-sm font-medium text-chrome-500 hover:underline">{{ __('Cancel') }}</button>
                    @elseif ($status === 'approved')
                        <button wire:click="startMaintenance" class="o-btn-primary text-sm">{{ __('Start maintenance') }}</button>
                        <button wire:click="cancelMaintenance" wire:confirm="{{ __('Cancel this work order?') }}" class="text-sm font-medium text-chrome-500 hover:underline">{{ __('Cancel') }}</button>
                    @elseif ($status === 'in_progress')
                        <button wire:click="completeMaintenance" wire:confirm="{{ __('Mark this maintenance complete and free the car?') }}" class="o-btn-primary text-sm">{{ __('Mark complete') }}</button>
                    @elseif ($status === 'done')
                        <span class="text-[11px] font-medium text-emerald-600">{{ __('Completed — car available') }}</span>
                    @endif
                </div>
            </div>
            {{-- A simple stage tracker so the process reads at a glance. --}}
            @unless (in_array($status, ['declined', 'cancelled'], true))
                @php
                    $stages = ['pending' => __('Requested'), 'approved' => __('Approved'), 'in_progress' => __('In progress'), 'done' => __('Completed')];
                    $order = ['pending' => 0, 'approved' => 1, 'in_progress' => 2, 'done' => 3];
                    $cur = $order[$status] ?? 0;
                @endphp
                <div class="mt-3 flex items-center gap-1.5 text-[11px]">
                    @foreach ($stages as $key => $label)
                        @php $i = $order[$key]; $done = $i <= $cur; @endphp
                        <span class="flex items-center gap-1.5 {{ $done ? 'text-primary-700' : 'text-chrome-300' }}">
                            <span class="flex size-4 items-center justify-center rounded-full text-[9px] font-bold {{ $done ? 'bg-primary-400 text-chrome-900' : 'bg-chrome-100 text-chrome-400' }}">{{ $i + 1 }}</span>
                            {{ $label }}
                        </span>
                        @if (! $loop->last)<span class="h-px w-4 {{ $i < $cur ? 'bg-primary-300' : 'bg-chrome-200' }}"></span>@endif
                    @endforeach
                </div>
            @endunless
        </div>
    @endif

    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/[0.06]">
        <h2 class="mb-4 text-sm font-semibold text-chrome-800">{{ __('Maintenance record') }}</h2>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Car') }} *</label>
                @php $carLocked = $isEditing && ! in_array($status, ['pending', 'approved'], true); @endphp
                <select wire:model.live="vehicle_id" class="o-input w-full" @disabled($carLocked)>
                    <option value="">{{ __('— Select —') }}</option>
                    @foreach ($vehicles as $v)
                        <option value="{{ $v->id }}">{{ $v->displayName() }} ({{ __(ucfirst($v->status)) }})</option>
                    @endforeach
                </select>
                @error('vehicle_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                @if ($carLocked)
                    <p class="mt-1 text-xs text-chrome-400">{{ __('The car can’t be changed once maintenance has started.') }}</p>
                @endif
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Priority') }}</label>
                <select wire:model="priority" class="o-input w-full">
                    @foreach ($priorityOptions as $opt)
                        <option value="{{ $opt['value'] }}">{{ __($opt['label']) }}</option>
                    @endforeach
                </select>
                @error('priority') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
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
            <span wire:loading.remove wire:target="save">{{ $isEditing ? __('Save') : __('Raise work order') }}</span>
            <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
        </button>
    </div>
</div>
