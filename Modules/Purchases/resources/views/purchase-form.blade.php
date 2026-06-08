@php use App\Erp\Money\Currencies; @endphp
<div class="mx-auto max-w-4xl p-4 sm:p-6">
    <div class="mb-4 flex items-center gap-2 text-sm text-chrome-500">
        <a href="{{ url('/app/purchases/purchase') }}" wire:navigate class="hover:text-primary-700">{{ __('Purchases') }}</a>
        <span>/</span>
        <span class="font-medium text-chrome-700">{{ $reference ?? __('New purchase') }}</span>
    </div>

    @if ($justConfirmed)
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            <p class="font-semibold">{{ __('Bill confirmed.') }}</p>
            <p class="mt-0.5">{{ __('POS stock and warehouse stock were both increased, and the accounting entry was posted.') }}</p>
        </div>
    @endif

    <form wire:submit.prevent="save" class="rounded-xl bg-white shadow-sm ring-1 ring-chrome-900/5">
        <div class="flex h-12 items-center justify-between border-b border-chrome-200 px-4">
            <h2 class="text-sm font-semibold text-chrome-800">
                {{ $isConfirmed ? __('Purchase') : ($reference ? __('Edit purchase') : __('New purchase')) }}
            </h2>
            <div class="flex items-center gap-2">
                @if ($isConfirmed)
                    <span class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-semibold text-emerald-700">
                        <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.704 5.296a1 1 0 0 1 0 1.408l-7.5 7.5a1 1 0 0 1-1.408 0l-3.5-3.5a1 1 0 0 1 1.408-1.408L8.5 12.09l6.796-6.795a1 1 0 0 1 1.408 0Z" clip-rule="evenodd"/></svg>
                        {{ __('Confirmed') }}
                    </span>
                @else
                    @if ($canWrite || $canCreate)
                        <button type="submit"
                            class="rounded-md px-3 py-1.5 text-sm font-medium text-chrome-600 ring-1 ring-chrome-300 hover:bg-chrome-50">
                            {{ __('Save draft') }}
                        </button>
                        <button type="button" wire:click="confirm" wire:loading.attr="disabled" wire:target="confirm"
                            class="o-btn-primary disabled:cursor-not-allowed disabled:opacity-60">
                            {{ __('Confirm') }}
                        </button>
                    @endif
                @endif
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4 p-6 sm:grid-cols-2">
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Vendor') }}</label>
                <select wire:model="form.partner_id" @disabled($isConfirmed) class="o-input">
                    <option value="">—</option>
                    @foreach ($vendors as $vendor)
                        <option value="{{ $vendor->id }}">{{ $vendor->name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Date') }} <span class="text-red-500">*</span></label>
                <input type="date" wire:model="form.date" @disabled($isConfirmed) class="o-input">
                @error('form.date') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Reference') }}</label>
                <input type="text" wire:model="form.reference" @disabled($isConfirmed)
                    placeholder="{{ __('Auto (or vendor invoice no.)') }}" class="o-input">
            </div>

            <div class="flex items-end">
                <label class="inline-flex items-center gap-2">
                    <input type="checkbox" wire:model="form.is_stock_purchase" @disabled($isConfirmed)
                        class="rounded border-chrome-300 text-primary-600 focus:ring-primary-500">
                    <span class="text-sm text-chrome-600">{{ __('Stock purchase (adds to inventory)') }}</span>
                </label>
            </div>
        </div>

        {{-- Lines --}}
        <div class="border-t border-chrome-200 px-6 py-4">
            <div class="mb-2 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-chrome-700">{{ __('Products') }}</h3>
                @unless ($isConfirmed)
                    <button type="button" wire:click="addLine"
                        class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium text-primary-700 hover:bg-primary-50">
                        <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10 5a1 1 0 0 1 1 1v3h3a1 1 0 1 1 0 2h-3v3a1 1 0 1 1-2 0v-3H6a1 1 0 1 1 0-2h3V6a1 1 0 0 1 1-1Z"/></svg>
                        {{ __('Add line') }}
                    </button>
                @endunless
            </div>

            @error('lines') <p class="mb-2 text-xs text-red-600">{{ $message }}</p> @enderror

            <div class="overflow-hidden rounded-lg ring-1 ring-chrome-200">
                <table class="w-full text-sm">
                    <thead class="bg-chrome-50 text-xs uppercase tracking-wide text-chrome-500">
                        <tr>
                            <th class="px-3 py-2 text-start font-semibold">{{ __('Product') }}</th>
                            <th class="px-3 py-2 text-end font-semibold">{{ __('Qty') }}</th>
                            <th class="px-3 py-2 text-end font-semibold">{{ __('Unit cost') }}</th>
                            <th class="px-3 py-2 text-end font-semibold">{{ __('Subtotal') }}</th>
                            @unless ($isConfirmed) <th class="w-10 px-3 py-2"></th> @endunless
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-chrome-100">
                        @foreach ($lines as $i => $line)
                            <tr wire:key="line-{{ $i }}">
                                <td class="px-3 py-2">
                                    <select wire:model.live="lines.{{ $i }}.pos_product_id" @disabled($isConfirmed) class="o-input">
                                        <option value="">—</option>
                                        @foreach ($products as $product)
                                            <option value="{{ $product->id }}">{{ $product->name }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td class="px-3 py-2 text-end">
                                    <input type="number" step="any" min="0" wire:model.live.debounce.400ms="lines.{{ $i }}.quantity"
                                        @disabled($isConfirmed) class="o-input w-24 text-end">
                                </td>
                                <td class="px-3 py-2 text-end">
                                    <input type="number" step="any" min="0" wire:model.live.debounce.400ms="lines.{{ $i }}.unit_cost"
                                        @disabled($isConfirmed) class="o-input w-28 text-end">
                                </td>
                                <td class="px-3 py-2 text-end font-medium text-chrome-700">
                                    {{ Currencies::format((float) ($line['quantity'] ?? 0) * (float) ($line['unit_cost'] ?? 0)) }}
                                </td>
                                @unless ($isConfirmed)
                                    <td class="px-3 py-2 text-end">
                                        <button type="button" wire:click="removeLine({{ $i }})"
                                            class="text-chrome-400 transition hover:text-red-600" title="{{ __('Remove') }}">
                                            <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8.75 1a1 1 0 0 0-.96.71L7.56 2.5H4a.75.75 0 0 0 0 1.5h12a.75.75 0 0 0 0-1.5h-3.56l-.23-.79A1 1 0 0 0 11.25 1h-2.5ZM5.5 6.5 6 16a2 2 0 0 0 2 1.9h4a2 2 0 0 0 2-1.9l.5-9.5h-9Z" clip-rule="evenodd"/></svg>
                                        </button>
                                    </td>
                                @endunless
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="bg-chrome-50">
                        <tr>
                            <td class="px-3 py-2 text-end font-semibold text-chrome-600" colspan="3">{{ __('Total') }}</td>
                            <td class="px-3 py-2 text-end font-bold text-chrome-900">{{ Currencies::format($total) }}</td>
                            @unless ($isConfirmed) <td></td> @endunless
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <div class="border-t border-chrome-200 px-6 py-4">
            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Notes') }}</label>
            <textarea wire:model="form.notes" rows="2" @disabled($isConfirmed) class="o-input resize-none"></textarea>
        </div>
    </form>
</div>
