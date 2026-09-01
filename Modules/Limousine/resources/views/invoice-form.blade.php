<div class="mx-auto max-w-5xl p-4 sm:p-6">
    <x-form-breadcrumb :parent="__('Invoices')" :parent-url="url('/app/limousine/invoice')" :current="$isEditing ? ($reference ?: __('Invoice')) : __('New invoice')" />

    @php
        $statusBadge = ['unpaid' => 'bg-amber-100 text-amber-700', 'partial' => 'bg-sky-100 text-sky-700', 'paid' => 'bg-emerald-100 text-emerald-700'][$status] ?? 'bg-chrome-200 text-chrome-700';
    @endphp

    @if ($isEditing)
        <div class="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/[0.06]">
            <div class="flex items-center gap-3">
                <span class="text-sm font-semibold text-chrome-800">{{ $reference }}</span>
                <span class="rounded px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide {{ $statusBadge }}">{{ __(ucfirst($status)) }}</span>
                @if ($booking_id)
                    <a href="{{ url('/app/limousine/booking/' . $booking_id) }}" wire:navigate class="text-xs text-chrome-500 hover:underline">{{ __('View booking') }}</a>
                @endif
            </div>
            <div class="flex items-center gap-3">
                <span class="text-sm text-chrome-500">{{ __('Balance') }}: <span class="font-semibold text-chrome-800">{{ \App\Erp\Views\ValueFormat::money($balance) }}</span></span>
                {{-- Dispatch the journey this bill is for. Only on an invoice
                     raised from a quotation: that is what holds the legs. --}}
                @if ($canCreateTrip)
                    <button type="button" wire:click="createTrip" class="o-btn-ghost text-sm">{{ __('Create trip') }}</button>
                @endif
                {{-- Taking money here goes through the same service the bookings
                     queue uses, so both doors write one receipt and one truth.
                     It replaced a link to a blank receipt form, which asked the
                     office to retype what the invoice already knew. --}}
                @if ($canCollect)
                    <button type="button" wire:click="openCollect" class="o-btn-primary text-sm">{{ __('Receive payment') }}</button>
                @endif
            </div>
        </div>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/[0.06]">
                <h2 class="mb-4 text-sm font-semibold text-chrome-800">{{ __('Invoice details') }}</h2>
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
                    <div></div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Issue date') }} *</label>
                        <x-date-field wire:model="issue_date" class="o-input w-full" />
                        @error('issue_date') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Due date') }}</label>
                        <x-date-field wire:model="due_date" class="o-input w-full" />
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
                <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/[0.06]">
                    <h2 class="mb-3 text-sm font-semibold text-chrome-800">{{ __('Payments') }}</h2>
                    @if ($receipts->isEmpty())
                        <p class="text-sm text-chrome-400">{{ __('No payments recorded yet.') }}</p>
                    @else
                        <ul class="divide-y divide-chrome-100 text-sm">
                            @foreach ($receipts as $r)
                                <li wire:key="lrcpt-{{ $r->id }}" class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 py-2">
                                    {{-- Downloads the receipt rather than opening an editor: the reference
                                         is what somebody clicks when they want the customer's copy. --}}
                                    <a href="{{ url('/app/limousine/receipt/' . $r->id . '/download') }}" class="font-medium text-primary-700 hover:underline">{{ $r->reference }}</a>
                                    <span class="text-chrome-500">{{ $r->date?->isoFormat('DD-MMM-YYYY') }}</span>
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
            <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/[0.06]">
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

    {{-- Receive payment --}}
    @if ($collecting)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-chrome-900/50 p-4"
             x-on:keydown.escape.window="$wire.closeCollect()">
            <div class="w-full max-w-md rounded-2xl bg-white p-5 shadow-xl sm:p-6" x-on:click.outside="$wire.closeCollect()">
                <h2 class="text-sm font-semibold text-chrome-800">{{ __('Receive payment') }}</h2>
                <p class="mt-1 text-xs text-chrome-500">
                    {{ __('Balance') }}: <span class="font-semibold">{{ \App\Erp\Views\ValueFormat::money($balance) }}</span>
                </p>

                <div class="mt-4 space-y-4">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Amount') }}</label>
                        <input type="number" step="0.001" min="0" wire:model="collectAmount" class="o-input w-full">
                        @error('collectAmount') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Method') }}</label>
                        <select wire:model="collectMethod" class="o-input w-full">
                            <option value="cash">{{ __('Cash') }}</option>
                            <option value="card">{{ __('Card') }}</option>
                            <option value="benefit">{{ __('Benefit') }}</option>
                            <option value="transfer">{{ __('Transfer') }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Note') }}</label>
                        <input type="text" wire:model="collectNote" class="o-input w-full">
                    </div>
                </div>

                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" wire:click="closeCollect" class="o-btn-ghost text-sm">{{ __('Close') }}</button>
                    <button type="button" wire:click="saveCollect" wire:loading.attr="disabled" class="o-btn-primary text-sm">{{ __('Receive payment') }}</button>
                </div>
            </div>
        </div>
    @endif
</div>
