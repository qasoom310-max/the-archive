@php
    $money = fn ($v) => \App\Erp\Money\Currencies::format((float) $v);
    $canSubmit = $splittable && $movedUnits >= 1 && $remainingUnits >= 1;
    $destLabel = collect($tableOptions)->firstWhere('value', $destTable)['label'] ?? __('No table (walk-in)');
@endphp

<div>
    @if ($open && $order)
        <div class="fixed inset-0 z-[70] flex items-start justify-center overflow-y-auto bg-chrome-900/50 p-4">
            <div class="my-6 flex w-full max-w-3xl flex-col rounded-2xl bg-white shadow-pop">
                {{-- Header --}}
                <div class="flex items-start justify-between gap-4 border-b border-chrome-200 px-6 py-4">
                    <div class="min-w-0">
                        <h2 class="text-lg font-bold text-chrome-900">{{ __('Split Order') }} {{ $order->reference }}</h2>
                        <p class="mt-0.5 text-sm text-chrome-500">{{ __('Select items to split into a new order. The selected items will be moved to a new order.') }}</p>
                    </div>
                    <button type="button" wire:click="close" aria-label="{{ __('Close') }}"
                        class="shrink-0 text-chrome-400 hover:text-chrome-700">✕</button>
                </div>

                <div class="space-y-5 px-6 py-5">
                    @if ($error !== '')
                        <div class="rounded-lg bg-red-50 px-3 py-2 text-sm font-medium text-red-700">{{ $error }}</div>
                    @endif

                    @if (! $splittable)
                        <div class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-700">
                            {{ __('This order needs at least two items before it can be split.') }}
                        </div>
                    @endif

                    {{-- Items selector --}}
                    <div>
                        <h3 class="mb-2 text-sm font-semibold text-chrome-800">{{ __('Select Items to Split') }}</h3>
                        <div class="overflow-hidden rounded-xl border border-chrome-200">
                            <table class="w-full text-sm">
                                <thead class="bg-chrome-50 text-xs uppercase tracking-wide text-chrome-500">
                                    <tr>
                                        <th class="w-10 px-3 py-2"></th>
                                        <th class="px-3 py-2 text-start font-semibold">{{ __('Item') }}</th>
                                        <th class="px-3 py-2 text-center font-semibold">{{ __('Available') }}</th>
                                        <th class="px-3 py-2 text-end font-semibold">{{ __('Unit Price') }}</th>
                                        <th class="px-3 py-2 text-end font-semibold">{{ __('Total Price') }}</th>
                                        <th class="px-3 py-2 text-center font-semibold">{{ __('Quantity') }}</th>
                                        <th class="px-3 py-2 text-end font-semibold">{{ __('Split Total') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-chrome-100">
                                    @foreach ($rows as $row)
                                        <tr wire:key="split-row-{{ $row['id'] }}" class="{{ $row['move'] > 0 ? 'bg-primary-50/50' : '' }}">
                                            <td class="px-3 py-2.5 text-center">
                                                <input type="checkbox" wire:click="toggleLine({{ $row['id'] }})"
                                                    @checked($row['move'] > 0)
                                                    class="size-4 rounded border-chrome-300 text-primary-500 focus:ring-primary-400">
                                            </td>
                                            <td class="px-3 py-2.5 font-medium text-chrome-800">{{ $row['name'] }}</td>
                                            <td class="px-3 py-2.5 text-center tabular-nums text-chrome-600">{{ $row['available'] }}</td>
                                            <td class="px-3 py-2.5 text-end tabular-nums text-chrome-600">{{ $money($row['unit_price']) }}</td>
                                            <td class="px-3 py-2.5 text-end tabular-nums text-chrome-600">{{ $money($row['line_total']) }}</td>
                                            <td class="px-3 py-2.5 text-center">
                                                <input type="number" min="0" max="{{ $row['available'] }}" inputmode="numeric"
                                                    wire:model.live="move.{{ $row['id'] }}"
                                                    class="o-input w-20 text-center text-sm tabular-nums">
                                            </td>
                                            <td class="px-3 py-2.5 text-end font-semibold tabular-nums text-chrome-900">
                                                {{ $row['move'] > 0 ? $money($row['split_total']) : '—' }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>

                    {{-- Destination + notes --}}
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Table for New Order') }}</label>
                            <select wire:model.live="destTable" class="o-input w-full text-sm">
                                @foreach ($tableOptions as $option)
                                    <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Notes (Optional)') }}</label>
                            <textarea wire:model="notes" rows="2" class="o-input w-full text-sm" placeholder="{{ __('Notes (Optional)') }}"></textarea>
                        </div>
                    </div>

                    {{-- Live split summary --}}
                    <div class="rounded-xl border border-chrome-200 p-4">
                        <h3 class="mb-3 text-sm font-semibold text-chrome-800">{{ __('Split Summary') }}</h3>

                        <div class="flex items-center justify-between">
                            <span class="text-sm font-semibold text-chrome-900">{{ __('New Order') }}</span>
                            <span class="rounded-full bg-chrome-900 px-2.5 py-0.5 text-xs font-medium text-white">
                                {{ trans_choice(':count item|:count items', $movedUnits, ['count' => $movedUnits]) }}
                            </span>
                        </div>
                        <div class="mt-2 flex items-center justify-between border-b border-chrome-100 pb-3">
                            <div class="text-sm">
                                <p class="font-medium text-chrome-700">{{ __('New Order Total') }}</p>
                                <p class="text-xs text-chrome-400">{{ __('Table') }}: {{ $destLabel }}</p>
                                @if (trim($notes) !== '')
                                    <p class="text-xs italic text-chrome-400">{{ __('Note') }}: {{ $notes }}</p>
                                @endif
                            </div>
                            <span class="text-base font-bold tabular-nums text-chrome-900">{{ $money($movedTotal) }}</span>
                        </div>

                        <div class="mt-3 flex items-center justify-between">
                            <span class="text-sm font-semibold text-chrome-900">{{ __('Original Order (Remaining)') }}</span>
                            <span class="rounded-full border border-chrome-300 px-2.5 py-0.5 text-xs font-medium text-chrome-600">
                                {{ trans_choice(':count item|:count items', $remainingUnits, ['count' => $remainingUnits]) }}
                            </span>
                        </div>
                        <div class="mt-2 flex items-center justify-between">
                            <span class="text-sm font-medium text-chrome-700">{{ __('Remaining Total') }}</span>
                            <span class="text-base font-bold tabular-nums text-chrome-900">{{ $money($remainingTotal) }}</span>
                        </div>
                    </div>
                </div>

                {{-- Footer --}}
                <div class="flex items-center justify-end gap-2 border-t border-chrome-200 px-6 py-4">
                    <button type="button" wire:click="close" class="o-btn-ghost">{{ __('Cancel') }}</button>
                    <button type="button" wire:click="submit" @disabled(! $canSubmit)
                        class="o-btn-primary {{ $canSubmit ? '' : 'cursor-not-allowed opacity-50' }}">
                        {{ __('Split Order') }}
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
