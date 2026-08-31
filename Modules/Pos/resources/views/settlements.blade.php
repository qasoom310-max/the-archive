@php
    $money = fn ($v) => \App\Erp\Money\Currencies::format($v);
    $badge = [
        'amber' => 'bg-amber-100 text-amber-700',
        'emerald' => 'bg-emerald-100 text-emerald-700',
    ];
@endphp

<div class="mx-auto max-w-6xl p-4 sm:p-6">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold text-chrome-900">{{ __('Delivery money') }}</h1>
            <p class="text-sm text-chrome-500">{{ __('What the delivery company still holds, what you asked for, and what actually landed.') }}</p>
        </div>
        <a href="{{ url('/app/pos/remote') }}" wire:navigate class="o-btn-ghost shrink-0">{{ __('Remote orders') }}</a>
    </div>

    @if (session('settlement_status'))
        <div class="mb-4 rounded-lg bg-primary-50 px-4 py-2.5 text-sm font-medium text-chrome-800 ring-1 ring-primary-200">
            {{ session('settlement_status') }}
        </div>
    @endif

    {{-- The three states money can be in. --}}
    <div class="mb-5 grid gap-3 sm:grid-cols-3">
        <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/5">
            <p class="text-[11px] font-semibold uppercase tracking-wide text-chrome-500">{{ __('Held by delivery company') }}</p>
            <p class="mt-1 text-lg font-bold text-amber-600">{{ $money($pending['expected']) }}</p>
            <p class="mt-0.5 text-[11px] text-chrome-400">
                {{ trans_choice('{0}no orders|{1}:count order|[2,*]:count orders', $pending['orders'], ['count' => $pending['orders']]) }}
                · {{ __('collected') }} {{ $money($pending['collected']) }} − {{ __('their fees') }} {{ $money($pending['fees']) }}
            </p>
        </div>
        <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/5">
            <p class="text-[11px] font-semibold uppercase tracking-wide text-chrome-500">{{ __('Requested, not yet arrived') }}</p>
            <p class="mt-1 text-lg font-bold text-chrome-800">{{ $money($awaitingTransfer) }}</p>
        </div>
        <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/5">
            <p class="text-[11px] font-semibold uppercase tracking-wide text-chrome-500">{{ __('Request a payout') }}</p>
            @if ($canManage)
                <button type="button" wire:click="requestPayout"
                    @disabled($pending['orders'] === 0)
                    class="o-btn-primary mt-2 w-full justify-center disabled:opacity-40">
                    {{ __('Request') }} {{ $money($pending['expected']) }}
                </button>
            @else
                <p class="mt-2 text-xs text-chrome-400">{{ __('You can view this, but not request payouts.') }}</p>
            @endif
        </div>
    </div>

    {{-- One transfer often settles several requests — tick them and record it
         once. The lump is split across them in proportion to what each was
         owed, so the parts add back up to the transfer exactly. --}}
    @if ($canManage && count($selected) > 0)
        <div class="mb-3 rounded-xl bg-primary-50 p-4 ring-1 ring-primary-200">
            @if (! $bulkReceiving)
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="text-sm font-medium text-chrome-800">
                        {{ trans_choice('{1}:count payout selected|[2,*]:count payouts selected', count($selected), ['count' => count($selected)]) }}
                        · {{ __('Expected') }} <span class="font-bold">{{ $money($selectedExpected) }}</span>
                    </p>
                    <div class="flex items-center gap-2">
                        <button type="button" wire:click="openBulkReceive" class="o-btn-primary">{{ __('One transfer covers these') }}</button>
                        <button type="button" wire:click="$set('selected', [])" class="o-btn-ghost">{{ __('Clear') }}</button>
                    </div>
                </div>
            @else
                <div class="flex flex-wrap items-end gap-3">
                    <div>
                        <label class="block text-xs font-medium text-chrome-500">{{ __('Total amount received') }}</label>
                        <input type="number" step="0.001" min="0" wire:model="receivedAmount" class="o-input mt-1 w-40 text-end">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-chrome-500">{{ __('How') }}</label>
                        <select wire:model="receivedMethod" class="o-input mt-1 w-40 text-sm">
                            <option value="bank_transfer">{{ __('Bank transfer') }}</option>
                            <option value="cash">{{ __('Cash') }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-chrome-500">{{ __('Date') }}</label>
                        <x-date-field wire:model="receivedOn" class="o-input mt-1 w-40 text-sm" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-chrome-500">{{ __('Transfer reference') }}</label>
                        <input type="text" wire:model="receiptReference" class="o-input mt-1 w-44 text-sm"
                            placeholder="{{ __('e.g. bank ref') }}">
                    </div>
                    <div class="min-w-[10rem] flex-1">
                        <label class="block text-xs font-medium text-chrome-500">{{ __('Note (optional)') }}</label>
                        <input type="text" wire:model="receiptNote" class="o-input mt-1 w-full text-sm">
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="button" wire:click="confirmBulkReceive" class="o-btn-primary">{{ __('Confirm') }}</button>
                        <button type="button" wire:click="cancelBulkReceive" class="o-btn-ghost">{{ __('Cancel') }}</button>
                    </div>
                </div>
                <p class="mt-2 text-xs text-chrome-500">
                    {{ trans_choice('{1}:count payout|[2,*]:count payouts', count($selected), ['count' => count($selected)]) }}
                    · {{ __('Expected') }}: <span class="font-semibold">{{ $money($selectedExpected) }}</span>
                    <span class="text-chrome-400">{{ __('— the transfer is split across them in proportion to what each is owed.') }}</span>
                </p>
                @error('receivedAmount') <p class="mt-1 text-xs font-medium text-red-600">{{ $message }}</p> @enderror
            @endif
        </div>
    @endif

    {{-- Payout history + reconciliation --}}
    <div class="overflow-x-auto rounded-xl border border-chrome-200 bg-white">
        <table class="w-full min-w-[880px] text-sm">
            <thead class="bg-chrome-50 text-xs uppercase tracking-wide text-chrome-500">
                <tr>
                    <th class="w-8 px-3 py-2.5"></th>
                    <th class="px-4 py-2.5 text-start font-semibold">{{ __('Payout') }}</th>
                    <th class="px-4 py-2.5 text-start font-semibold">{{ __('Requested') }}</th>
                    <th class="px-4 py-2.5 text-center font-semibold">{{ __('Orders') }}</th>
                    <th class="px-4 py-2.5 text-end font-semibold">{{ __('Expected') }}</th>
                    <th class="px-4 py-2.5 text-end font-semibold">{{ __('Received') }}</th>
                    <th class="px-4 py-2.5 text-end font-semibold">{{ __('Difference') }}</th>
                    <th class="px-4 py-2.5 text-start font-semibold">{{ __('Status') }}</th>
                    <th class="px-4 py-2.5 text-end font-semibold">{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-chrome-100">
                @forelse ($settlements as $s)
                    <tr wire:key="stl-{{ $s->id }}" class="hover:bg-chrome-50">
                        <td class="px-3 py-2.5">
                            {{-- Only a payout still waiting for money can be
                                 part of a transfer. --}}
                            @if ($canManage && ! $s->isReceived())
                                <input type="checkbox" wire:model.live="selected" value="{{ $s->id }}"
                                    aria-label="{{ __('Select :ref', ['ref' => $s->reference]) }}"
                                    class="size-4 rounded border-chrome-300 text-primary-600 focus:ring-primary-500">
                            @endif
                        </td>
                        <td class="px-4 py-2.5 font-semibold text-chrome-900">
                            {{ $s->reference }}
                            @if ($s->receipt_reference)
                                <span class="block text-[11px] font-normal text-chrome-400">{{ $s->receipt_reference }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2.5 whitespace-nowrap text-chrome-500">{{ $s->requested_at?->isoFormat('DD-MMM, h:mm A') ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-center tabular-nums text-chrome-600">{{ $s->orders_count }}</td>
                        <td class="px-4 py-2.5 text-end tabular-nums text-chrome-700">{{ $money($s->expected_amount) }}</td>
                        <td class="px-4 py-2.5 text-end tabular-nums font-medium text-chrome-900">
                            {{ $s->isReceived() ? $money($s->received_amount) : '—' }}
                        </td>
                        <td class="px-4 py-2.5 text-end tabular-nums">
                            @if (! $s->isReceived())
                                <span class="text-chrome-400">—</span>
                            @elseif ($s->hasDiscrepancy())
                                <span class="font-semibold text-red-600">{{ $s->difference > 0 ? '+' : '' }}{{ $money($s->difference) }}</span>
                            @else
                                <span class="font-medium text-emerald-600">✓ {{ __('matches') }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2.5">
                            <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium {{ $badge[$s->state->color()] ?? 'bg-chrome-100 text-chrome-700' }}">
                                {{ __($s->state->label()) }}
                            </span>
                        </td>
                        <td class="px-4 py-2.5 text-end">
                            @if ($canManage && ! $s->isReceived())
                                <div class="flex flex-wrap items-center justify-end gap-2">
                                    <button type="button" wire:click="openReceive({{ $s->id }})"
                                        class="text-xs font-semibold text-primary-700 hover:underline">{{ __('Money arrived') }}</button>
                                    <button type="button" wire:click="cancelRequest({{ $s->id }})"
                                        wire:confirm="{{ __('Cancel this payout request? Its orders go back to awaiting settlement.') }}"
                                        class="text-xs text-red-600 hover:underline">{{ __('Cancel') }}</button>
                                </div>
                            @elseif ($s->received_at)
                                <span class="text-xs text-chrome-400">{{ $s->received_at->isoFormat('DD-MMM') }}</span>
                            @endif
                        </td>
                    </tr>

                    {{-- Inline "money arrived" form, prefilled with the expected
                         amount so the cashier only edits a wrong transfer. --}}
                    @if ($receivingId === $s->id)
                        <tr wire:key="recv-{{ $s->id }}" class="bg-primary-50/40">
                            <td colspan="9" class="px-4 py-3">
                                <div class="flex flex-wrap items-end gap-3">
                                    <div>
                                        <label class="block text-xs font-medium text-chrome-500">{{ __('Amount received') }}</label>
                                        <input type="number" step="0.001" min="0" wire:model="receivedAmount" class="o-input mt-1 w-36 text-end">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-chrome-500">{{ __('How') }}</label>
                                        <select wire:model="receivedMethod" class="o-input mt-1 w-40 text-sm">
                                            <option value="bank_transfer">{{ __('Bank transfer') }}</option>
                                            <option value="cash">{{ __('Cash') }}</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-chrome-500">{{ __('Date') }}</label>
                                        <x-date-field wire:model="receivedOn" class="o-input mt-1 w-40 text-sm" />
                                    </div>
                                    <div class="min-w-[10rem] flex-1">
                                        <label class="block text-xs font-medium text-chrome-500">{{ __('Note (optional)') }}</label>
                                        <input type="text" wire:model="receiptNote" class="o-input mt-1 w-full text-sm"
                                            placeholder="{{ __('e.g. transfer ref, or why it differs') }}">
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <button type="button" wire:click="confirmReceive" class="o-btn-primary">{{ __('Confirm') }}</button>
                                        <button type="button" wire:click="cancelReceive" class="o-btn-ghost">{{ __('Cancel') }}</button>
                                    </div>
                                </div>
                                <p class="mt-2 text-xs text-chrome-500">
                                    {{ __('Expected') }}: <span class="font-semibold">{{ $money($s->expected_amount) }}</span>
                                    <span class="text-chrome-400">
                                        ({{ __('collected') }} {{ $money($s->collected_total) }} − {{ __('their fees') }} {{ $money($s->fees_deducted) }})
                                    </span>
                                </p>
                                @error('receivedAmount') <p class="mt-1 text-xs font-medium text-red-600">{{ $message }}</p> @enderror
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr>
                        <td colspan="9" class="px-4 py-12 text-center text-sm text-chrome-400">
                            {{ __('No payouts yet. Request one when the delivery company is holding your money.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
