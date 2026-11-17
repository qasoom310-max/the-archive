{{--
    Scan a bill. Step 1: take or choose a photo. Step 2: check what was read,
    with the photo beside it, and save. Built for a phone: one column, large
    buttons, and the camera opens straight from the "Take a photo" button.
--}}
@php
    $lbl = 'mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500';
    $money = static fn (float $v, ?string $code = null): string => \App\Erp\Money\Currencies::format($v, $code);
@endphp

<div class="mx-auto max-w-3xl p-4 sm:p-6"
     x-data="{
        busy: false, error: '', preview: '',
        async send(e) {
            const file = e.target.files[0];
            if (!file) return;
            this.busy = true; this.error = '';
            if (this.preview) URL.revokeObjectURL(this.preview);
            this.preview = URL.createObjectURL(file);
            const data = new FormData();
            data.append('file', file);
            try {
                const r = await fetch(@js(url('/app/limousine/expense/scan')), {
                    method: 'POST', body: data, credentials: 'same-origin',
                    headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' },
                });
                const j = await r.json().catch(() => ({}));
                if (!r.ok) {
                    this.error = (j.errors && Object.values(j.errors)[0][0]) || j.message || @js(__('Upload failed.'));
                    this.preview = '';
                    return;
                }
                await $wire.review(j.token);
            } catch (err) {
                this.error = err.message || @js(__('Upload failed.'));
                this.preview = '';
            } finally {
                this.busy = false; e.target.value = '';
            }
        },
     }">
    <x-form-breadcrumb :parent="__('Expenses')" :parent-url="url('/app/limousine/expense')" :current="__('Scan a bill')" />

    @if (! $available)
        <div class="rounded-2xl bg-white p-6 text-sm text-chrome-600 shadow-sm ring-1 ring-chrome-900/5" data-scan-unavailable>
            <p class="font-semibold text-chrome-800">{{ __('Bill scanning is not set up yet.') }}</p>
            <p class="mt-1">{{ __('It reads bills with Claude and needs the Claude API key. Add it in WhatsApp → Assistant settings, then come back.') }}</p>
        </div>
    @elseif (! $reviewing)
        <div class="rounded-2xl bg-white p-6 text-center shadow-sm ring-1 ring-chrome-900/5" data-scan-start>
            <h1 class="text-lg font-bold text-chrome-900">{{ __('Scan a bill') }}</h1>
            <p class="mx-auto mt-1 max-w-md text-sm text-chrome-500">{{ __('Take a photo of the bill. The date, supplier, amount and VAT are read for you to check. Arabic and English bills both work.') }}</p>

            <div class="mt-5 flex flex-col items-center gap-3 sm:flex-row sm:justify-center">
                <label class="o-btn-primary w-full cursor-pointer justify-center py-3 sm:w-auto" :class="busy ? 'pointer-events-none opacity-50' : ''">
                    <svg class="size-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M1 8a2 2 0 0 1 2-2h.93a2 2 0 0 0 1.664-.89l.812-1.22A2 2 0 0 1 8.07 3h3.86a2 2 0 0 1 1.664.89l.812 1.22A2 2 0 0 0 16.07 6H17a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8Zm13.5 3a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0ZM10 14a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z" clip-rule="evenodd"/></svg>
                    {{ __('Take a photo') }}
                    <input type="file" accept="image/jpeg,image/png,image/webp" capture="environment" class="hidden" @change="send($event)">
                </label>
                <label class="o-btn-ghost w-full cursor-pointer justify-center py-3 sm:w-auto" :class="busy ? 'pointer-events-none opacity-50' : ''">
                    {{ __('Choose a picture') }}
                    <input type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="send($event)">
                </label>
            </div>

            <p x-show="busy" x-cloak class="mt-4 text-sm font-medium text-chrome-600">{{ __('Reading the bill… this takes a few seconds.') }}</p>
            <p x-show="error" x-cloak x-text="error" class="mt-4 text-sm font-medium text-red-600" role="alert"></p>
            @error('scan') <p class="mt-4 text-sm font-medium text-red-600">{{ $message }}</p> @enderror

            <ul class="mx-auto mt-6 max-w-md space-y-1 text-start text-xs text-chrome-400">
                <li>{{ __('Lay the bill flat, in good light, with the whole bill in the picture.') }}</li>
                <li>{{ __('Nothing is saved until you check the figures and press Save.') }}</li>
            </ul>
        </div>
    @else
        <div class="grid gap-5 md:grid-cols-2" data-scan-review>
            <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5">
                <h1 class="text-base font-bold text-chrome-900">{{ __('Check what was read') }}</h1>
                <p class="mb-3 text-xs text-chrome-500">{{ __('Compare each box with the bill and correct anything that is wrong.') }}</p>

                @if ($check !== [])
                    <div class="mb-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800" data-scan-check>
                        @foreach ($check as $line)<p>{{ $line }}</p>@endforeach
                    </div>
                @endif

                <div class="space-y-3">
                    <div>
                        <label class="{{ $lbl }}">{{ __('Date') }} <span class="text-red-500">*</span></label>
                        <x-date-field wire:model="date" class="o-input w-full" />
                        @error('date') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="{{ $lbl }}">{{ __('Category') }}</label>
                        <select wire:model="category" class="o-input w-full">
                            @foreach ($categories as $option)<option value="{{ $option['value'] }}">{{ __($option['label']) }}</option>@endforeach
                        </select>
                        @error('category') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="{{ $lbl }}">{{ __('Paid to') }}</label>
                        <input type="text" wire:model="payee" class="o-input w-full">
                        @error('payee') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="grid grid-cols-2 gap-3" data-scan-currency>
                        <div>
                            <label class="{{ $lbl }}">{{ __('Currency') }}</label>
                            <select wire:model.live="currency" class="o-input w-full">
                                @foreach ($currencies as $code)<option value="{{ $code }}">{{ $code }}</option>@endforeach
                            </select>
                            @error('currency') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        @if ($foreign)
                            <div>
                                <label class="{{ $lbl }}">{{ __('1 :currency in BHD', ['currency' => $shownCurrency]) }}</label>
                                <input type="number" step="any" min="0" inputmode="decimal" wire:model.live.debounce.300ms="exchange_rate" class="o-input w-full">
                                @error('exchange_rate') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>
                        @endif
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="{{ $lbl }}">{{ __('Amount before VAT') }}@if ($foreign) ({{ $shownCurrency }})@endif <span class="text-red-500">*</span></label>
                            <input type="number" step="any" min="0" inputmode="decimal" wire:model.live.debounce.300ms="net_amount" class="o-input w-full">
                            @error('net_amount') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="{{ $lbl }}">{{ __('VAT') }}</label>
                            <select wire:model.live="vat_rate" class="o-input w-full">
                                @foreach ($vatOptions as $option)<option value="{{ $option['value'] }}">{{ __($option['label']) }}</option>@endforeach
                            </select>
                            @error('vat_rate') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <dl class="rounded-lg bg-chrome-50 px-3 py-2 text-sm" data-scan-totals>
                        @if ($foreign)
                            <div class="flex justify-between"><dt class="text-chrome-500">{{ __('Total on the bill') }}</dt><dd class="tabular-nums text-chrome-700" dir="ltr">{{ $money($total, $shownCurrency) }}</dd></div>
                            <p class="mb-1 mt-2 border-t border-chrome-200 pt-2 text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Saved in BHD') }}</p>
                            <div class="flex justify-between"><dt class="text-chrome-500">{{ __('Amount before VAT') }}</dt><dd class="tabular-nums text-chrome-700" dir="ltr">{{ $money($bhdNet, 'BHD') }}</dd></div>
                        @endif
                        <div class="flex justify-between"><dt class="text-chrome-500">{{ __('VAT amount') }}</dt><dd class="tabular-nums text-chrome-700" dir="ltr">{{ $money($bhdVat, 'BHD') }}</dd></div>
                        <div class="mt-1 flex justify-between font-bold"><dt class="text-chrome-800">{{ __('Total') }}</dt><dd class="tabular-nums text-chrome-900" dir="ltr">{{ $money($bhdTotal, 'BHD') }}</dd></div>
                    </dl>
                    <div>
                        <label class="{{ $lbl }}">{{ __('Notes') }}</label>
                        <textarea wire:model="notes" rows="2" class="o-input w-full"></textarea>
                        @error('notes') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>

                @error('scan') <p class="mt-3 text-sm font-medium text-red-600">{{ $message }}</p> @enderror

                <div class="mt-4 flex flex-wrap gap-2">
                    <button type="button" wire:click="save" wire:loading.attr="disabled" class="o-btn-primary flex-1 justify-center py-2.5">{{ __('Save expense') }}</button>
                    <button type="button" wire:click="discard" @click="preview = ''" class="o-btn-ghost">{{ __('Scan another') }}</button>
                </div>
            </div>

            <div class="rounded-2xl bg-white p-3 shadow-sm ring-1 ring-chrome-900/5">
                <p class="mb-2 px-2 text-xs font-semibold uppercase tracking-wide text-chrome-400">{{ __('The bill') }}</p>
                <template x-if="preview">
                    <img :src="preview" alt="{{ __('The bill') }}" class="w-full rounded-lg">
                </template>
                <template x-if="!preview">
                    <p class="px-2 py-6 text-center text-xs text-chrome-400">{{ __('The photo is kept with the expense when you save.') }}</p>
                </template>
            </div>
        </div>
    @endif
</div>
