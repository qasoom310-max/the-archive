@php
    use App\Erp\Money\Currencies;
    $money = fn ($v) => Currencies::format((float) $v);
    $catLabels = collect($categories)->pluck('label', 'value');
    $netNeg = $financials['net'] < 0;
@endphp

<div class="mx-auto max-w-5xl p-4 sm:p-6">
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold text-chrome-900">{{ __('Profit & Expenses') }}</h1>
            <p class="text-sm text-chrome-500">{{ __('Real monthly profit: sales minus cost of goods sold minus your expenses.') }}</p>
        </div>
        <div>
            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Month') }}</label>
            <input type="month" wire:model.live="month" class="o-input">
        </div>
    </div>

    <p class="mb-3 text-sm font-medium text-chrome-600">{{ $monthLabel }}</p>

    {{-- P&L cards --}}
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
        <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/5">
            <p class="text-[11px] font-semibold uppercase tracking-wide text-chrome-500">{{ __('Sales') }}</p>
            <p class="mt-1 text-lg font-bold text-emerald-600">{{ $money($financials['sales']) }}</p>
        </div>
        <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/5">
            <p class="text-[11px] font-semibold uppercase tracking-wide text-chrome-500">{{ __('Cost of goods sold') }}</p>
            <p class="mt-1 text-lg font-bold text-chrome-700">−{{ $money($financials['cogs']) }}</p>
        </div>
        <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/5">
            <p class="text-[11px] font-semibold uppercase tracking-wide text-chrome-500">{{ __('Gross Profit') }}</p>
            <p class="mt-1 text-lg font-bold {{ $financials['gross'] < 0 ? 'text-red-600' : 'text-chrome-900' }}">{{ $money($financials['gross']) }}</p>
        </div>
        <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/5">
            <p class="text-[11px] font-semibold uppercase tracking-wide text-chrome-500">{{ __('Expenses') }}</p>
            <p class="mt-1 text-lg font-bold text-chrome-700">−{{ $money($financials['expenses']) }}</p>
        </div>
        <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/5">
            <p class="text-[11px] font-semibold uppercase tracking-wide text-chrome-500">{{ __('Payroll') }}</p>
            <p class="mt-1 text-lg font-bold text-chrome-700">−{{ $money($financials['payroll']) }}</p>
        </div>
        {{-- Delivery we absorbed (what we paid the driver). The delivery
             customers paid for is shown underneath as context only — it is
             already counted inside Sales, so it is NOT added again here. --}}
        <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/5">
            <p class="text-[11px] font-semibold uppercase tracking-wide text-chrome-500">{{ __('Delivery (we paid)') }}</p>
            <p class="mt-1 text-lg font-bold text-chrome-700">−{{ $money($financials['delivery']) }}</p>
            @if ($financials['delivery_recovered'] > 0)
                <p class="mt-0.5 text-[11px] font-medium text-emerald-600">
                    {{ __('Customers paid') }}: {{ $money($financials['delivery_recovered']) }}
                </p>
            @endif
        </div>
        <div class="rounded-xl p-4 shadow-sm ring-1 {{ $netNeg ? 'bg-red-50 ring-red-200' : 'bg-emerald-50 ring-emerald-200' }}">
            <p class="text-[11px] font-semibold uppercase tracking-wide {{ $netNeg ? 'text-red-600' : 'text-emerald-700' }}">{{ __('Net Profit') }}</p>
            <p class="mt-1 text-lg font-bold {{ $netNeg ? 'text-red-600' : 'text-emerald-700' }}">{{ $money($financials['net']) }}</p>
        </div>
    </div>
    <p class="mt-2 text-xs text-chrome-400">
        {{ __('Net Profit = Sales − Cost of goods sold − Expenses − Payroll − Delivery we paid. Delivery the customer paid is already inside Sales.') }}
    </p>

    {{-- Profit ≠ cash. A sale counts the moment it's made, so a healthy month can
         sit alongside money that hasn't arrived: a pay-on-delivery order is Done
         before anyone hands over cash, and once collected the delivery company
         holds it until the payout. Spell out where that money actually is. --}}
    @if ($financials['not_in_account'] > 0)
        <div class="mt-4 rounded-xl bg-amber-50 p-4 ring-1 ring-amber-200">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-sm font-bold text-amber-900">
                        {{ __('Not in your account yet') }}: {{ $money($financials['not_in_account']) }}
                    </p>
                    <p class="mt-1 text-xs text-amber-800">
                        {{ __('Sales counts an order when it is sold, not when the money arrives. Of the sales above, this much has not reached you yet:') }}
                    </p>
                    <ul class="mt-1.5 space-y-0.5 text-xs text-amber-800">
                        @if ($financials['uncollected'] > 0)
                            <li>• {{ __('Not yet collected from customers') }}: <span class="font-semibold">{{ $money($financials['uncollected']) }}</span></li>
                        @endif
                        @if ($financials['in_transit'] > 0)
                            <li>• {{ __('Collected, still held by the delivery company') }}: <span class="font-semibold">{{ $money($financials['in_transit']) }}</span></li>
                        @endif
                    </ul>
                </div>
                <a href="{{ url('/app/pos/settlements') }}" wire:navigate class="o-btn-ghost shrink-0 text-sm">{{ __('Delivery money') }}</a>
            </div>
        </div>
    @endif

    {{-- Recurring bills --}}
    <div class="mt-6 rounded-xl bg-white shadow-sm ring-1 ring-chrome-900/5">
        <div class="flex items-center justify-between border-b border-chrome-200 px-4 py-3">
            <h2 class="text-sm font-semibold text-chrome-800">{{ __('Recurring expenses') }}</h2>
            <button wire:click="openAddBill" class="o-btn-primary">
                <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10 5a1 1 0 0 1 1 1v3h3a1 1 0 1 1 0 2h-3v3a1 1 0 1 1-2 0v-3H6a1 1 0 1 1 0-2h3V6a1 1 0 0 1 1-1Z"/></svg>
                {{ __('Add bill') }}
            </button>
        </div>

        <table class="min-w-full divide-y divide-chrome-200 text-sm">
            <thead class="bg-chrome-50 text-xs uppercase tracking-wide text-chrome-500">
                <tr>
                    <th class="px-4 py-2.5 text-start font-semibold">{{ __('Bill') }}</th>
                    <th class="px-4 py-2.5 text-start font-semibold">{{ __('Category') }}</th>
                    <th class="px-4 py-2.5 text-end font-semibold">{{ __('Usual amount') }}</th>
                    <th class="px-4 py-2.5 text-start font-semibold">{{ __('This month') }}</th>
                    <th class="px-4 py-2.5"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-chrome-100">
                @forelse ($bills as $bill)
                    @php $payment = $bill->payments->first(); @endphp
                    <tr wire:key="bill-{{ $bill->id }}" class="hover:bg-chrome-50">
                        <td class="px-4 py-2.5 font-medium text-chrome-800">
                            {{ $bill->name }}
                            @if ($bill->due_day)<span class="ms-1 text-xs text-chrome-400">({{ __('due day') }} {{ $bill->due_day }})</span>@endif
                        </td>
                        <td class="px-4 py-2.5 text-chrome-500">{{ $catLabels[$bill->category] ?? $bill->category }}</td>
                        <td class="px-4 py-2.5 text-end tabular-nums text-chrome-500">{{ $money($bill->amount) }}</td>
                        <td class="px-4 py-2.5">
                            @if ($payment)
                                <span class="inline-flex rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-700">
                                    {{ __('Paid') }} {{ $money($payment->amount) }}
                                </span>
                                <span class="ms-1 text-xs text-chrome-400">{{ $payment->paid_on->isoFormat('DD-MMM') }}</span>
                            @else
                                <span class="inline-flex rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700">{{ __('Due') }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2.5 text-end">
                            @if ($payingExpenseId === $bill->id)
                                {{-- Inline mark-paid editor --}}
                                <div class="flex items-center justify-end gap-2">
                                    <input type="number" step="0.001" min="0" wire:model="payAmount" class="o-input w-24 text-end" placeholder="{{ __('Amount') }}">
                                    <input type="date" wire:model="payDate" class="o-input w-36">
                                    <button wire:click="savePay" class="o-btn-primary">{{ __('Save') }}</button>
                                    <button wire:click="closePay" class="text-xs text-chrome-500 hover:underline">{{ __('Cancel') }}</button>
                                </div>
                                @error('payAmount') <p class="mt-1 text-end text-xs text-red-600">{{ $message }}</p> @enderror
                            @else
                                <div class="flex items-center justify-end gap-3">
                                    <button wire:click="openPay({{ $bill->id }})"
                                        class="text-xs font-medium text-primary-700 hover:underline">
                                        {{ $payment ? __('Edit') : __('Mark paid') }}
                                    </button>
                                    @if ($payment)
                                        <button wire:click="unmarkPaid({{ $bill->id }})"
                                            class="text-xs text-chrome-400 hover:underline">{{ __('Unmark') }}</button>
                                    @endif
                                    <button wire:click="removeBill({{ $bill->id }})"
                                        wire:confirm="{{ __('Remove this recurring bill?') }}"
                                        class="text-xs text-red-500 hover:underline">{{ __('Remove') }}</button>
                                </div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-10 text-center text-sm text-chrome-400">
                            {{ __('No recurring bills yet. Add Rent, EWA, SIO, LMRA…') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Add-bill modal --}}
    @if ($addingBill)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4"
            x-data x-on:keydown.escape.window="$wire.set('addingBill', false)">
            <div class="absolute inset-0 bg-chrome-900/40" wire:click="$set('addingBill', false)"></div>
            <div class="relative w-full max-w-md rounded-xl bg-white p-5 shadow-pop ring-1 ring-chrome-900/5">
                <h3 class="mb-4 text-sm font-semibold text-chrome-800">{{ __('New recurring bill') }}</h3>
                <form wire:submit.prevent="addBill" class="space-y-3">
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Name') }} <span class="text-red-500">*</span></label>
                        <input type="text" wire:model="newBill.name" placeholder="{{ __('e.g. Rent, EWA, SIO, LMRA') }}" class="o-input">
                        @error('newBill.name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Category') }}</label>
                            <select wire:model="newBill.category" class="o-input">
                                @foreach ($categories as $cat)
                                    <option value="{{ $cat['value'] }}">{{ __($cat['label']) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Usual amount') }}</label>
                            <input type="number" step="0.001" min="0" wire:model="newBill.amount" class="o-input">
                            @error('newBill.amount') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Due day') }}</label>
                        <input type="number" min="1" max="31" wire:model="newBill.due_day" placeholder="{{ __('1–31 (optional)') }}" class="o-input w-32">
                        @error('newBill.due_day') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" wire:click="$set('addingBill', false)"
                            class="rounded-md px-3 py-1.5 text-sm font-medium text-chrome-600 ring-1 ring-chrome-300 hover:bg-chrome-50">{{ __('Cancel') }}</button>
                        <button type="submit" class="o-btn-primary">{{ __('Add bill') }}</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
