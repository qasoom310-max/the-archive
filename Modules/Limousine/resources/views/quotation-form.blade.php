<div class="mx-auto max-w-4xl p-4 sm:p-6">
    <x-form-breadcrumb :parent="__('Quotations')" :parent-url="url('/app/limousine/quotation')" :current="$isEditing ? ($reference ?: __('Quotation')) : __('New quotation')" />

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
                    <button wire:click="convert" class="o-btn-primary text-sm">{{ __('Convert to booking') }}</button>
                @elseif ($booking_id)
                    <a href="{{ url('/app/limousine/booking/' . $booking_id) }}" wire:navigate class="o-btn-ghost text-sm">{{ __('Open booking') }}</a>
                @endif
            </div>
        </div>
    @endif

    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/[0.06]">
        <h2 class="mb-4 text-sm font-semibold text-chrome-800">{{ __('Quotation details') }}</h2>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Customer') }} *</label>
                <select wire:model="customer_id" class="o-input w-full">
                    <option value="">{{ __('— Select —') }}</option>
                    @foreach ($customers as $c)
                        <option value="{{ $c->id }}">{{ $c->name }}{{ $c->phone ? ' · ' . $c->phone : '' }}</option>
                    @endforeach
                </select>
                @error('customer_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Pick-up time') }} *</label>
                <input type="datetime-local" wire:model="pickup_at" class="o-input w-full">
                @error('pickup_at') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Pick-up location') }}</label>
                <select wire:model="pickup_location_id" class="o-input w-full">
                    <option value="">{{ __('— Select —') }}</option>
                    @foreach ($locations as $l)
                        <option value="{{ $l->id }}">{{ $l->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Dropoff location') }}</label>
                <select wire:model="dropoff_location_id" class="o-input w-full">
                    <option value="">{{ __('— Select —') }}</option>
                    @foreach ($locations as $l)
                        <option value="{{ $l->id }}">{{ $l->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Car type') }}</label>
                <select wire:model="car_type" class="o-input w-full">
                    @foreach ($carTypes as $opt)
                        <option value="{{ $opt['value'] }}">{{ __($opt['label']) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Valid until') }}</label>
                <input type="date" wire:model="valid_until" class="o-input w-full">
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Fare (BHD)') }} *</label>
                <input type="number" step="0.001" min="0" wire:model="fare" class="o-input w-full">
                @error('fare') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div class="sm:col-span-2">
                <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Notes') }}</label>
                <textarea wire:model="notes" rows="2" class="o-input w-full"></textarea>
            </div>
        </div>

        <button wire:click="save" class="o-btn-primary mt-4 w-full justify-center">
            <span wire:loading.remove wire:target="save">{{ $isEditing ? __('Save quotation') : __('Create quotation') }}</span>
            <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
        </button>
    </div>
</div>
