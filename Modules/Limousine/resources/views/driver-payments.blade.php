{{--
    Driver payments: what each outside driver is owed, paying them, and every
    payment made. Built from DriverBalances and LimoDriverPayment.
--}}
@php
    $money = static fn (float $v): string => \App\Erp\Views\ValueFormat::money($v);
    $lbl = 'mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500';
    $methodLabels = ['cash' => __('Cash'), 'transfer' => __('Transfer'), 'benefit' => __('Benefit')];
@endphp

<div class="mx-auto max-w-[1200px] px-4 py-6 sm:px-6" data-driver-payments>
    <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-chrome-900">{{ __('Driver payments') }}</h1>
            <p class="mt-1 text-sm text-chrome-500">{{ __('What each outside driver is owed, and every payment made to them.') }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-2 print:hidden">
            <a href="{{ url('/app/limousine/outside-drivers') }}" wire:navigate class="o-btn-ghost text-sm">{{ __('Outside drivers') }}</a>
            <button type="button" onclick="window.print()" class="o-btn-ghost text-sm">{{ __('Print') }}</button>
        </div>
    </div>

    <div class="mb-6 grid gap-4 sm:grid-cols-2">
        <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5">
            <div class="text-[11px] font-bold uppercase tracking-wide text-chrome-400">{{ __('Owed now') }}</div>
            <div class="mt-2 text-2xl font-bold tracking-tight text-chrome-900" data-total-owed>{{ $money($totalOwed) }}</div>
            <p class="mt-1 text-xs text-chrome-500">{{ __('Trips driven from :date up to now, less what was paid.', ['date' => $start->format('j-M-Y')]) }}</p>
        </div>
        <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5">
            <div class="text-[11px] font-bold uppercase tracking-wide text-chrome-400">{{ __('Booked ahead') }}</div>
            <div class="mt-2 text-2xl font-bold tracking-tight text-chrome-900">{{ $money($totalAhead) }}</div>
            <p class="mt-1 text-xs text-chrome-500">{{ __('Trips given to drivers that have not happened yet. Owed once they are driven.') }}</p>
        </div>
    </div>

    {{-- Each driver: earned, paid, owed. --}}
    <div class="mb-6 overflow-x-auto rounded-2xl bg-white shadow-sm ring-1 ring-chrome-900/5" data-balances>
        <table class="w-full min-w-[640px] text-sm">
            <thead class="bg-chrome-50 text-xs font-semibold uppercase tracking-wide text-chrome-500">
                <tr>
                    <th class="px-4 py-2 text-start">{{ __('Driver') }}</th>
                    <th class="px-4 py-2 text-end">{{ __('Trips') }}</th>
                    <th class="px-4 py-2 text-end">{{ __('Earned') }}</th>
                    <th class="px-4 py-2 text-end">{{ __('Paid') }}</th>
                    <th class="px-4 py-2 text-end">{{ __('Owed') }}</th>
                    <th class="px-4 py-2 text-end print:hidden"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-chrome-50">
                @forelse ($balances as $b)
                    <tr wire:key="bal-{{ $b['id'] }}" data-balance="{{ $b['id'] }}">
                        <td class="px-4 py-2 font-medium text-chrome-800">{{ $b['name'] }}</td>
                        <td class="px-4 py-2 text-end text-chrome-600">{{ $b['trips'] }}</td>
                        <td class="px-4 py-2 text-end text-chrome-700">{{ $money($b['earned']) }}</td>
                        <td class="px-4 py-2 text-end text-emerald-700">{{ $money($b['paid']) }}</td>
                        <td class="px-4 py-2 text-end font-bold {{ $b['owed'] > 0 ? 'text-chrome-900' : 'text-chrome-400' }}">
                            @if ($b['owed'] < 0)
                                {{ __('Paid ahead :amount', ['amount' => $money(-$b['owed'])]) }}
                            @else
                                {{ $money($b['owed']) }}
                            @endif
                        </td>
                        <td class="whitespace-nowrap px-4 py-2 text-end print:hidden">
                            @if ($mayPay)
                                <button type="button" wire:click="openPay({{ $b['id'] }})" class="o-btn-primary px-3 py-1 text-xs" data-pay="{{ $b['id'] }}">{{ __('Pay') }}</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-8 text-center text-sm text-chrome-400">{{ __('No outside drivers yet.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Every payment made. --}}
    <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-base font-semibold text-chrome-900">{{ __('Payments made') }}</h2>
        <select wire:model.live="driverFilter" class="o-input w-56 py-1.5 text-sm print:hidden">
            <option value="0">{{ __('All drivers') }}</option>
            @foreach ($driverOptions as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach
        </select>
    </div>
    <div class="mb-6 overflow-x-auto rounded-2xl bg-white shadow-sm ring-1 ring-chrome-900/5" data-payments>
        <table class="w-full min-w-[820px] text-sm">
            <thead class="bg-chrome-50 text-xs font-semibold uppercase tracking-wide text-chrome-500">
                <tr>
                    <th class="px-4 py-2 text-start">{{ __('Date') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Reference') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Driver') }}</th>
                    <th class="px-4 py-2 text-end">{{ __('Amount') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Requested by') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Recorded by') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Method') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Note') }}</th>
                    <th class="px-4 py-2 print:hidden"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-chrome-50">
                @forelse ($payments as $p)
                    <tr wire:key="pay-{{ $p->id }}" class="{{ $p->isCancelled() ? 'opacity-60' : '' }}" data-payment="{{ $p->id }}">
                        <td class="whitespace-nowrap px-4 py-2 text-chrome-700">{{ $p->paid_on->format('j-M-Y') }}</td>
                        <td class="whitespace-nowrap px-4 py-2 font-medium text-chrome-800">{{ $p->reference }}</td>
                        <td class="px-4 py-2 text-chrome-700">{{ $p->driver?->name ?? '—' }}</td>
                        <td class="whitespace-nowrap px-4 py-2 text-end font-semibold {{ $p->isCancelled() ? 'text-chrome-400 line-through' : 'text-chrome-900' }}">{{ $money($p->amount) }}</td>
                        <td class="px-4 py-2 text-chrome-700">{{ $p->requestedBy?->name ?? '—' }}</td>
                        <td class="px-4 py-2 text-chrome-600">{{ $p->recordedBy?->name ?? '—' }}</td>
                        <td class="px-4 py-2 text-chrome-600">{{ $methodLabels[$p->method] ?? $p->method }}</td>
                        <td class="px-4 py-2 text-xs text-chrome-500">
                            {{ $p->note }}
                            @if ($p->isCancelled())
                                <div class="font-semibold text-red-600">{{ __('Cancelled by :name: :reason', ['name' => $p->cancelledBy?->name ?? '—', 'reason' => $p->cancel_reason]) }}</div>
                            @endif
                        </td>
                        <td class="whitespace-nowrap px-4 py-2 text-end print:hidden">
                            @if ($isOwner && ! $p->isCancelled())
                                <button type="button" wire:click="openCancel({{ $p->id }})" class="text-xs font-medium text-red-600 hover:underline">{{ __('Cancel') }}</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="px-4 py-8 text-center text-sm text-chrome-400">{{ __('No payments yet.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mb-6">{{ $payments->links('vendor.pagination.compact') }}</div>

    {{-- The owner: when trips start counting, and who may ask for a payment. --}}
    @if ($isOwner)
        <div class="grid gap-4 md:grid-cols-2 print:hidden" data-driver-pay-settings>
            <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5">
                <h3 class="text-sm font-semibold text-chrome-900">{{ __('Count trips from') }}</h3>
                <p class="mb-3 text-xs text-chrome-500">{{ __('Trips before this date were paid outside the system, so they are not counted as owed.') }}</p>
                <div class="flex items-end gap-2">
                    <input type="date" wire:model="startDate" class="o-input">
                    <button type="button" wire:click="saveStart" class="o-btn-primary text-sm">{{ __('Save') }}</button>
                </div>
                @error('startDate') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5">
                <h3 class="text-sm font-semibold text-chrome-900">{{ __('Who can ask for a payment') }}</h3>
                <p class="mb-3 text-xs text-chrome-500">{{ __('Only the people ticked here can be chosen as "Requested by".') }}</p>
                <div class="max-h-48 space-y-1 overflow-y-auto">
                    @foreach ($staff as $u)
                        <label class="flex items-center gap-2 text-sm text-chrome-700" wire:key="req-{{ $u->id }}">
                            <input type="checkbox" wire:click="toggleRequester({{ $u->id }})" @checked($u->getAttribute('can_request_driver_payments')) class="size-4 rounded border-chrome-300 text-primary-600">
                            {{ $u->name }}
                        </label>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    {{-- Pay a driver. --}}
    @if ($paying !== null || $payingName !== '')
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-chrome-900/50 p-4" x-on:keydown.escape.window="$wire.closePay()">
            <div class="max-h-[90vh] w-full max-w-md overflow-y-auto rounded-2xl bg-white p-5 shadow-xl sm:p-6" x-on:click.outside="$wire.closePay()" data-pay-form>
                <h2 class="text-sm font-semibold text-chrome-800">{{ __('Pay :driver', ['driver' => $payingName]) }}</h2>
                <p class="mt-1 text-xs text-chrome-500">
                    {{ __('Owed now') }}: <span class="font-semibold text-chrome-800">{{ $money(max(0.0, $paying['owed'] ?? 0.0)) }}</span>
                </p>

                <div class="mt-4 space-y-4">
                    <div>
                        <label class="{{ $lbl }}">{{ __('Amount') }} <span class="text-red-500">*</span></label>
                        <div class="flex gap-2">
                            <input type="number" step="0.001" min="0" inputmode="decimal" wire:model="amount" class="o-input w-full">
                            <button type="button" wire:click="payFull" class="o-btn-ghost whitespace-nowrap text-sm ring-1 ring-chrome-200" data-pay-full>{{ __('Pay full') }}</button>
                        </div>
                        @error('amount') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        <label class="mt-2 flex items-center gap-2 text-xs text-chrome-600">
                            <input type="checkbox" wire:model="confirmAhead" class="size-4 rounded border-chrome-300 text-primary-600">
                            {{ __('I am paying more than is owed on purpose (paid ahead).') }}
                        </label>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="{{ $lbl }}">{{ __('Date') }} <span class="text-red-500">*</span></label>
                            <input type="date" wire:model="paidOn" class="o-input w-full">
                            @error('paidOn') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="{{ $lbl }}">{{ __('Method') }}</label>
                            <select wire:model="method" class="o-input w-full">
                                @foreach ($methods as $m)<option value="{{ $m }}">{{ $methodLabels[$m] ?? $m }}</option>@endforeach
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="{{ $lbl }}">{{ __('Requested by') }} <span class="text-red-500">*</span></label>
                        <select wire:model="requestedBy" class="o-input w-full" data-requested-by>
                            <option value="">{{ __('Choose…') }}</option>
                            @foreach ($requesters as $r)<option value="{{ $r->id }}">{{ $r->name }}</option>@endforeach
                        </select>
                        @if ($requesters->isEmpty())
                            <p class="mt-1 text-xs text-amber-700">{{ __('Nobody is allowed to ask for payments yet. The owner ticks them at the bottom of this page.') }}</p>
                        @endif
                        @error('requestedBy') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="{{ $lbl }}">{{ __('Note') }}</label>
                        <input type="text" wire:model="note" maxlength="500" class="o-input w-full">
                    </div>
                </div>

                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" wire:click="closePay" class="o-btn-ghost text-sm">{{ __('Close') }}</button>
                    <button type="button" wire:click="savePay" wire:loading.attr="disabled" class="o-btn-primary text-sm">{{ __('Save payment') }}</button>
                </div>
            </div>
        </div>
    @endif

    {{-- Cancel a payment (owner). --}}
    @if ($cancellingId !== null)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-chrome-900/50 p-4" x-on:keydown.escape.window="$wire.closeCancel()">
            <div class="w-full max-w-md rounded-2xl bg-white p-5 shadow-xl" x-on:click.outside="$wire.closeCancel()">
                <h2 class="text-sm font-semibold text-chrome-800">{{ __('Cancel this payment') }}</h2>
                <p class="mt-1 text-xs text-chrome-500">{{ __('It stays in the list, crossed out, and stops counting as paid.') }}</p>
                <label class="mt-4 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Reason') }} <span class="text-red-500">*</span></label>
                <input type="text" wire:model="cancelReason" maxlength="500" class="o-input mt-1 w-full">
                @error('cancelReason') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" wire:click="closeCancel" class="o-btn-ghost text-sm">{{ __('Close') }}</button>
                    <button type="button" wire:click="confirmCancel" class="rounded-lg bg-red-600 px-3 py-1.5 text-sm font-semibold text-white">{{ __('Cancel payment') }}</button>
                </div>
            </div>
        </div>
    @endif
</div>
