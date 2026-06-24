<div class="mx-auto max-w-5xl p-4 sm:p-6">
    <div class="mb-4 flex items-center gap-2 text-sm text-chrome-500">
        <a href="{{ url('/app/limousine/invoice') }}" wire:navigate class="hover:text-primary-700">{{ __('Invoices') }}</a>
        <span>/</span>
        <span class="font-medium text-chrome-700">{{ $isEditing ? ($reference ?: __('Invoice')) : __('New invoice') }}</span>
    </div>

    @php
        $statusBadge = ['unpaid' => 'bg-amber-100 text-amber-700', 'partial' => 'bg-sky-100 text-sky-700', 'paid' => 'bg-emerald-100 text-emerald-700'][$status] ?? 'bg-chrome-200 text-chrome-700';
    @endphp

    @if ($isEditing)
        <div class="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/5">
            <div class="flex items-center gap-3">
                <span class="text-sm font-semibold text-chrome-800">{{ $reference }}</span>
                <span class="rounded px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide {{ $statusBadge }}">{{ __(ucfirst($status)) }}</span>
                @if ($booking_id)
                    <a href="{{ url('/app/limousine/booking/' . $booking_id) }}" wire:navigate class="text-xs text-chrome-500 hover:underline">{{ __('View booking') }}</a>
                @endif
            </div>
            <div class="flex items-center gap-3">
                <span class="text-sm text-chrome-500">{{ __('Balance') }}: <span class="font-semibold text-chrome-800">{{ \App\Erp\Views\ValueFormat::money($balance) }}</span></span>
                @if ($status !== 'paid')
                    <a href="{{ url('/app/limousine/receipt/new?invoice=' . $id) }}" wire:navigate class="o-btn-primary text-sm">{{ __('Record payment') }}</a>
                @endif
            </div>
        </div>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5">
                <h2 class="mb-4 text-sm font-semibold text-chrome-800">{{ __('Invoice details') }}</h2>
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
                    <div></div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Issue date') }} *</label>
                        <input type="date" wire:model="issue_date" class="o-input w-full">
                        @error('issue_date') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Due date') }}</label>
                        <input type="date" wire:model="due_date" class="o-input w-full">
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Subtotal (BHD)') }} *</label>
                        <input type="number" step="0.001" min="0" wire:model.live="subtotal" class="o-input w-full">
                        @error('subtotal') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Discount (BHD)') }}</label>
                        <input type="number" step="0.001" min="0" wire:model.live="discount" class="o-input w-full">
                    </div>
                </div>
                <div class="mt-4">
                    <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Notes') }}</label>
                    <textarea wire:model="notes" rows="2" class="o-input w-full"></textarea>
                </div>
            </div>

            @if ($isEditing)
                <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5">
                    <h2 class="mb-3 text-sm font-semibold text-chrome-800">{{ __('Payments') }}</h2>
                    @if ($receipts->isEmpty())
                        <p class="text-sm text-chrome-400">{{ __('No payments recorded yet.') }}</p>
                    @else
                        <ul class="divide-y divide-chrome-100 text-sm">
                            @foreach ($receipts as $r)
                                <li wire:key="lrcpt-{{ $r->id }}" class="flex items-center justify-between py-2">
                                    <a href="{{ url('/app/limousine/receipt/' . $r->id) }}" wire:navigate class="font-medium text-primary-700 hover:underline">{{ $r->reference }}</a>
                                    <span class="text-chrome-500">{{ $r->date?->isoFormat('MMM D, YYYY') }}</span>
                                    <span class="text-chrome-500">{{ __(ucfirst($r->method)) }}</span>
                                    <span class="font-semibold text-chrome-800">{{ \App\Erp\Views\ValueFormat::money($r->amount) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endif
        </div>

        <div class="space-y-4">
            <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5">
                <h2 class="mb-3 text-sm font-semibold text-chrome-800">{{ __('Summary') }}</h2>
                <dl class="space-y-2 text-sm">
                    <div class="border-t border-chrome-100 pt-2 flex justify-between text-base"><dt class="font-semibold text-chrome-700">{{ __('Total') }}</dt><dd class="font-bold text-chrome-900">{{ \App\Erp\Views\ValueFormat::money($previewTotal) }}</dd></div>
                    @if ($isEditing)
                        <div class="flex justify-between"><dt class="text-chrome-500">{{ __('Paid') }}</dt><dd class="font-medium text-emerald-700">{{ \App\Erp\Views\ValueFormat::money($amount_paid) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-chrome-500">{{ __('Balance') }}</dt><dd class="font-semibold text-chrome-800">{{ \App\Erp\Views\ValueFormat::money($balance) }}</dd></div>
                    @endif
                </dl>
                <button wire:click="save" class="o-btn-primary mt-4 w-full justify-center">
                    <span wire:loading.remove wire:target="save">{{ $isEditing ? __('Save invoice') : __('Create invoice') }}</span>
                    <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
                </button>
            </div>
        </div>
    </div>
</div>
