<div class="mx-auto max-w-3xl p-4 sm:p-6">
    <x-form-breadcrumb :parent="__('Receipts')" :parent-url="url('/app/limousine/receipt')" :current="$isEditing ? ($reference ?: __('Receipt')) : __('New receipt')" />

    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/[0.06]">
        <h2 class="mb-4 text-sm font-semibold text-chrome-800">{{ __('Record payment') }}</h2>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Invoice') }} *</label>
                {{-- Searchable: the list grows by the day, so type part of the
                     invoice number or the customer's name to narrow it. --}}
                <x-searchable-select wire:model.live="invoice_id" class="o-input w-full"
                    :search-placeholder="__('Search invoice number or customer…')"
                    :options="$invoices->map(fn ($inv) => [
                        'value' => $inv->id,
                        'label' => $inv->reference . ' · ' . ($inv->customer?->name ?? __('—')) . ' · ' . \App\Erp\Views\ValueFormat::money($inv->balance()) . ' ' . __('due'),
                    ])->all()" />
                @error('invoice_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Date') }} *</label>
                <x-date-field wire:model="date" class="o-input w-full" />
                @error('date') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Amount (BHD)') }} *</label>
                <input type="number" step="0.001" min="0" wire:model="amount" class="o-input w-full">
                @error('amount') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Method') }}</label>
                <select wire:model="method" class="o-input w-full">
                    <option value="cash">{{ __('Cash') }}</option>
                    <option value="card">{{ __('Card') }}</option>
                    <option value="benefit">{{ __('Benefit') }}</option>
                    <option value="transfer">{{ __('Bank transfer') }}</option>
                    <option value="online">{{ __('Online (Tap)') }}</option>
                </select>
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Notes') }}</label>
                <input type="text" wire:model="notes" class="o-input w-full">
            </div>
        </div>

        @if ($selectedInvoice)
            <div class="mt-4 rounded-lg bg-chrome-50 p-3 text-sm">
                <div class="flex justify-between"><span class="text-chrome-500">{{ __('Invoice total') }}</span><span class="font-medium text-chrome-800">{{ \App\Erp\Views\ValueFormat::money($selectedInvoice->total) }}</span></div>
                <div class="flex justify-between"><span class="text-chrome-500">{{ __('Already paid') }}</span><span class="text-emerald-700">{{ \App\Erp\Views\ValueFormat::money($selectedInvoice->amount_paid) }}</span></div>
                <div class="flex justify-between border-t border-chrome-200 mt-1 pt-1"><span class="font-medium text-chrome-700">{{ __('Balance') }}</span><span class="font-bold text-chrome-900">{{ \App\Erp\Views\ValueFormat::money($selectedInvoice->balance()) }}</span></div>
            </div>
        @endif

        <button wire:click="save" class="o-btn-primary mt-4 w-full justify-center">
            <span wire:loading.remove wire:target="save">{{ __('Save payment') }}</span>
            <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
        </button>
    </div>
</div>
