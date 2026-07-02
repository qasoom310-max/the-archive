<div class="mx-auto max-w-5xl p-4 sm:p-6">
    <x-form-breadcrumb :parent="__('Quotations')" :parent-url="url('/app/limousine/quotation')" :current="$isEditing ? ($reference ?: __('Quotation')) : __('New quotation')" />

    @php
        $statusBadge = [
            'draft' => 'bg-chrome-200 text-chrome-700',
            'sent' => 'bg-sky-100 text-sky-700',
            'accepted' => 'bg-emerald-100 text-emerald-700',
            'declined' => 'bg-red-100 text-red-700',
            'converted' => 'bg-violet-100 text-violet-700',
        ][$status] ?? 'bg-chrome-200 text-chrome-700';
        $lbl = 'mb-1 block text-sm font-medium text-chrome-700';
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

    {{-- ── Header ── --}}
    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/[0.06] sm:p-6">
        <h2 class="mb-4 text-sm font-semibold text-chrome-800">{{ __('Quotation') }}</h2>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            @if ($isEditing)
                <div>
                    <label class="{{ $lbl }}">{{ __('Quotation number') }}</label>
                    <input type="text" value="{{ $reference }}" disabled class="o-input w-full bg-chrome-50 text-chrome-500">
                </div>
            @endif
            <div>
                <label class="{{ $lbl }}">{{ __('Date') }}</label>
                <input type="date" wire:model="quote_date" class="o-input w-full">
            </div>
            <div class="{{ $isEditing ? '' : 'sm:col-span-2' }}">
                <div class="mb-1 flex items-center justify-between gap-2">
                    <label class="block text-sm font-medium text-chrome-700">{{ __('Customer') }} *</label>
                    <button type="button" wire:click="openCustomerModal" class="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-xs font-medium text-primary-700 hover:bg-primary-50">
                        <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor"><path d="M10 5a1 1 0 0 1 1 1v3h3a1 1 0 1 1 0 2h-3v3a1 1 0 1 1-2 0v-3H6a1 1 0 1 1 0-2h3V6a1 1 0 0 1 1-1Z"/></svg>
                        {{ __('New customer') }}
                    </button>
                </div>
                <select wire:model="customer_id" class="o-input w-full">
                    <option value="">{{ __('— Select —') }}</option>
                    @foreach ($customers as $c)<option value="{{ $c->id }}">{{ $c->name }}{{ $c->phone ? ' · ' . $c->phone : '' }}</option>@endforeach
                </select>
                @error('customer_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="{{ $lbl }}">{{ __('Contact person') }}</label>
                <input type="text" wire:model="contact_person" class="o-input w-full">
            </div>
            <div>
                <label class="{{ $lbl }}">{{ __('Requested by') }} *</label>
                <input type="text" wire:model="requested_by" class="o-input w-full">
                @error('requested_by') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="{{ $lbl }}">{{ __('Prepared by') }} *</label>
                <input type="text" wire:model="prepared_by" class="o-input w-full">
                @error('prepared_by') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="{{ $lbl }}">{{ __('Contact number') }}</label>
                <input type="text" wire:model="contact_number" class="o-input w-full" placeholder="{{ __('Contact number of prepared person') }}">
            </div>
            <div>
                <label class="{{ $lbl }}">{{ __('Valid until') }}</label>
                <input type="date" wire:model="valid_until" class="o-input w-full">
            </div>
            <div class="sm:col-span-2">
                <label class="{{ $lbl }}">{{ __('Comments') }}</label>
                <textarea wire:model="notes" rows="2" class="o-input w-full"></textarea>
            </div>
        </div>
    </div>

    {{-- ── Trip legs ── --}}
    <div class="mt-5">
        @include('limousine::partials.legs')
    </div>

    {{-- ── Grand total + save ── --}}
    <div class="mt-5 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/[0.06] sm:p-6">
        <div class="flex items-center justify-between">
            <span class="text-sm font-semibold text-chrome-700">{{ __('Grand total') }} <span class="text-chrome-400">· {{ count($legs) }} {{ __('leg(s)') }}</span></span>
            <span class="text-2xl font-bold text-chrome-900">{{ \App\Erp\Views\ValueFormat::money($grandTotal) }}</span>
        </div>
        <button wire:click="save" class="o-btn-primary mt-4 w-full justify-center py-2.5">
            <span wire:loading.remove wire:target="save">{{ $isEditing ? __('Save quotation') : __('Add record') }}</span>
            <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
        </button>
        <a href="{{ url('/app/limousine/quotation') }}" wire:navigate class="mt-2 block text-center text-sm text-chrome-500 hover:text-chrome-700">{{ __('Cancel') }}</a>
    </div>

    @include('limousine::partials.customer-modal')
</div>
