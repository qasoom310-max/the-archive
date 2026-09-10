<div class="mx-auto max-w-7xl p-4 sm:p-6">
    <x-form-breadcrumb :parent="__('Customers')" :parent-url="url('/app/limousine/customer')" :current="$customer->name" />

    {{-- Who they are, and how to reach them. A corporate account books on
         behalf of its own guests, so the contact person matters as much as the
         company name. --}}
    <div class="mb-5 flex flex-wrap items-start justify-between gap-4 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/[0.06]">
        <div>
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="text-lg font-semibold text-chrome-900">{{ $customer->flag }} {{ $customer->name }}</h1>
                <span class="rounded px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide {{ $customer->isCompany() ? 'bg-indigo-100 text-indigo-700' : 'bg-chrome-200 text-chrome-700' }}">
                    {{ $customer->isCompany() ? __('Company') : __('Individual') }}
                </span>
                @if (! $customer->active)
                    <span class="rounded bg-chrome-200 px-2 py-0.5 text-[11px] font-semibold uppercase text-chrome-600">{{ __('Inactive') }}</span>
                @endif
            </div>
            <dl class="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-sm text-chrome-600">
                @if ($customer->phone)<div>{{ $customer->phone }}</div>@endif
                @if ($customer->email)<div>{{ $customer->email }}</div>@endif
                @if ($customer->contact_person)<div>{{ __('Contact') }}: {{ $customer->contact_person }}@if ($customer->contact_phone) · {{ $customer->contact_phone }}@endif</div>@endif
                @if ($customer->cr_number)<div>{{ __('CR') }}: {{ $customer->cr_number }}</div>@endif
            </dl>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ url('/app/limousine/customer/' . $customer->id) }}" wire:navigate class="o-btn-ghost text-sm">{{ __('Edit details') }}</a>
            <a href="{{ url('/app/limousine/booking/new') }}" wire:navigate class="o-btn-ghost text-sm">{{ __('New booking') }}</a>
            {{-- A company settles its account, not a trip — one figure across
                 everything it owes, oldest bill first. --}}
            @if ($settleable->isNotEmpty())
                <button type="button" wire:click="openPay" class="o-btn-primary text-sm">{{ __('Receive payment') }}</button>
            @endif
        </div>
    </div>

    {{-- The account in four figures. Money comes from the invoices, which is
         what the customer owes under — adding up trip fares would re-price
         history every time a job was edited. --}}
    <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @php
            $cards = [
                ['label' => __('Trips'), 'value' => $tripCount, 'money' => false, 'tone' => 'text-chrome-800'],
                ['label' => __('Billed'), 'value' => $billed, 'money' => true, 'tone' => 'text-chrome-800'],
                ['label' => __('Received'), 'value' => $received, 'money' => true, 'tone' => 'text-emerald-700'],
                ['label' => __('Outstanding'), 'value' => $outstanding, 'money' => true, 'tone' => $outstanding > 0 ? 'text-red-600' : 'text-emerald-700'],
            ];
        @endphp
        @foreach ($cards as $c)
            <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5">
                <div class="text-sm font-medium text-chrome-600">{{ $c['label'] }}</div>
                <div class="mt-2 text-2xl font-bold {{ $c['tone'] }}">
                    {{ $c['money'] ? \App\Erp\Views\ValueFormat::money($c['value']) : $c['value'] }}
                </div>
            </div>
        @endforeach
    </div>

    {{-- What the customer's accounts department asks for. The range is the
         statement's period, not a filter on this page — a list of unpaid bills
         cannot show that something WAS paid, which is what they reconcile. --}}
    <div class="mb-6 flex flex-wrap items-end gap-3 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/[0.06]">
        <div>
            <h2 class="mb-2 text-sm font-semibold text-chrome-800">{{ __('Statement of account') }}</h2>
            <p class="text-xs text-chrome-500">{{ __('Every charge and every payment, with receipt numbers.') }}</p>
        </div>
        <div class="ms-auto flex flex-wrap items-end gap-3">
            <div>
                <label class="mb-1 block text-xs font-medium text-chrome-500">{{ __('From') }}</label>
                <x-date-field wire:model.live="from" class="o-input text-sm" />
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-chrome-500">{{ __('To') }}</label>
                <x-date-field wire:model.live="to" class="o-input text-sm" />
            </div>
            <a href="{{ $statementUrl }}" class="o-btn-ghost text-sm">{{ __('Download statement') }}</a>
            {{-- Deciding the customer owes MORE than we quoted is a management
                 call, so it is an admin's button. --}}
            @if ($canCharge)
                <button type="button" wire:click="openFee" class="o-btn-ghost text-sm">{{ __('Add late fee') }}</button>
            @endif
        </div>
    </div>

    {{-- Asked for but not yet priced into a bill. The half of an account a
         list of trips cannot show. --}}
    @if ($openQuotes->isNotEmpty())
        <div class="mb-6 overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-chrome-900/[0.06]">
            <div class="flex items-center justify-between border-b border-chrome-100 px-4 py-3">
                <h2 class="text-sm font-semibold text-chrome-800">{{ __('Open quotations') }}</h2>
                <a href="{{ url('/app/limousine/quotation') }}" wire:navigate class="text-xs text-primary-700 hover:underline">{{ __('All quotations') }}</a>
            </div>
            <table class="w-full min-w-[560px] divide-y divide-chrome-100 text-sm">
                <tbody class="divide-y divide-chrome-50">
                    @foreach ($openQuotes as $q)
                        <tr wire:key="csq-{{ $q->id }}" class="hover:bg-chrome-50">
                            <td class="px-4 py-2 font-medium text-chrome-800">{{ $q->reference }}</td>
                            <td class="px-4 py-2 text-chrome-600">{{ $q->quote_date?->isoFormat('DD-MMM-YYYY') ?? '—' }}</td>
                            <td class="px-4 py-2 text-chrome-600">{{ __(ucfirst($q->status)) }}</td>
                            <td class="px-4 py-2 text-end font-medium text-chrome-800">{{ \App\Erp\Views\ValueFormat::money($q->fare) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- Bills still owed against. Pressing one hands over the document, the
         same as everywhere else. --}}
    @if ($openInvoices->isNotEmpty())
        <div class="mb-6 overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-chrome-900/[0.06]">
            <div class="flex items-center justify-between border-b border-chrome-100 px-4 py-3">
                <h2 class="text-sm font-semibold text-chrome-800">{{ __('Unsettled invoices') }}</h2>
                <a href="{{ url('/app/limousine/invoice') }}" wire:navigate class="text-xs text-primary-700 hover:underline">{{ __('All invoices') }}</a>
            </div>
            <table class="w-full min-w-[560px] divide-y divide-chrome-100 text-sm">
                <tbody class="divide-y divide-chrome-50">
                    @foreach ($openInvoices as $inv)
                        <tr wire:key="csi-{{ $inv->id }}" class="hover:bg-chrome-50">
                            <td class="px-4 py-2">
                                <a href="{{ url('/app/limousine/invoice/' . $inv->id . '/download') }}" class="font-medium text-primary-700 hover:underline">{{ $inv->reference }}</a>
                            </td>
                            <td class="px-4 py-2 text-chrome-600">{{ $inv->issue_date?->isoFormat('DD-MMM-YYYY') ?? '—' }}</td>
                            <td class="px-4 py-2 text-end text-chrome-600">{{ \App\Erp\Views\ValueFormat::money($inv->total) }}</td>
                            <td class="px-4 py-2 text-end font-semibold text-red-600">{{ \App\Erp\Views\ValueFormat::money($inv->balance()) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- Their trips, newest first — the same unit the queue dispatches, so
         this page and that one agree about what a job is. --}}
    <div class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-chrome-900/[0.06]">
        <div class="flex items-center justify-between border-b border-chrome-100 px-4 py-3">
            <h2 class="text-sm font-semibold text-chrome-800">{{ __('Recent trips') }}</h2>
            @if ($tripCount > $shown)
                {{-- The queue already searches by customer name, so "all of
                     them" is that list filtered rather than a second one. --}}
                <a href="{{ url('/app/limousine/booking?tab=all&search=' . urlencode($customer->name)) }}" wire:navigate class="text-xs text-primary-700 hover:underline">
                    {{ __('All :count trips', ['count' => $tripCount]) }}
                </a>
            @endif
        </div>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[720px] divide-y divide-chrome-100 text-sm">
                <thead class="bg-chrome-50 text-xs font-semibold uppercase tracking-wide text-chrome-500">
                    <tr>
                        <th class="px-4 py-2 text-start">{{ __('Reference') }}</th>
                        <th class="px-4 py-2 text-start">{{ __('Pick-up') }}</th>
                        <th class="px-4 py-2 text-start">{{ __('Route') }}</th>
                        <th class="px-4 py-2 text-start">{{ __('Status') }}</th>
                        <th class="px-4 py-2 text-end">{{ __('Amount') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-chrome-50">
                    @forelse ($legs as $leg)
                        @php
                            $lb = [
                                'queue' => 'bg-amber-100 text-amber-700',
                                'confirmed' => 'bg-sky-100 text-sky-700',
                                'active' => 'bg-indigo-100 text-indigo-700',
                                'completed' => 'bg-emerald-100 text-emerald-700',
                                'cancelled' => 'bg-red-100 text-red-700',
                            ][$leg->status] ?? 'bg-chrome-200 text-chrome-700';
                        @endphp
                        <tr wire:key="csl-{{ $leg->id }}" class="cursor-pointer hover:bg-chrome-50"
                            onclick="window.location='{{ url('/app/limousine/booking/' . $leg->legable_id) }}'">
                            <td class="px-4 py-2">
                                <div class="font-medium text-chrome-800">{{ $leg->reference ?: '—' }}</div>
                                <div class="text-xs text-chrome-400">{{ $leg->legable?->reference }}</div>
                            </td>
                            <td class="px-4 py-2 text-chrome-600">{{ $leg->start_at?->isoFormat('DD-MMM-YY HH:mm') ?? '—' }}</td>
                            <td class="px-4 py-2 text-chrome-700">{{ trim(($leg->from_location ?? '') . ' → ' . ($leg->to_location ?? ''), ' →') ?: '—' }}</td>
                            <td class="px-4 py-2"><span class="rounded px-2 py-0.5 text-[11px] font-semibold uppercase {{ $lb }}">{{ __(ucfirst($leg->status)) }}</span></td>
                            <td class="px-4 py-2 text-end font-medium text-chrome-800">{{ \App\Erp\Views\ValueFormat::money($leg->net_amount) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-10 text-center text-sm text-chrome-400">{{ __('No trips yet.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Pay on account. The allocation is shown before it happens: a payment
         that spreads itself silently is one nobody can check afterwards. --}}
    @if ($paying)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-chrome-900/50 p-4"
             x-on:keydown.escape.window="$wire.closePay()">
            <div class="w-full max-w-lg rounded-2xl bg-white p-5 shadow-xl sm:p-6" x-on:click.outside="$wire.closePay()">
                <h2 class="text-sm font-semibold text-chrome-800">{{ __('Receive payment') }}</h2>
                <p class="mt-1 text-xs text-chrome-500">
                    {{ __('Applied to the oldest unpaid invoice first.') }}
                </p>

                <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Amount') }}</label>
                        <input type="number" step="0.001" min="0" wire:model.live.debounce.400ms="payAmount" class="o-input w-full">
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Method') }}</label>
                        <select wire:model="payMethod" class="o-input w-full">
                            <option value="cash">{{ __('Cash') }}</option>
                            <option value="card">{{ __('Card') }}</option>
                            <option value="benefit">{{ __('Benefit') }}</option>
                            <option value="transfer">{{ __('Transfer') }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Note') }}</label>
                        <input type="text" wire:model="payNote" class="o-input w-full">
                    </div>
                </div>
                @error('payAmount') <p class="mt-2 text-xs text-red-600">{{ $message }}</p> @enderror

                {{-- Exactly which bills this clears, and which it only dents. --}}
                <div class="mt-4 rounded-xl ring-1 ring-chrome-900/[0.06]">
                    <div class="border-b border-chrome-100 px-3 py-2 text-xs font-semibold uppercase tracking-wide text-chrome-500">
                        {{ __('This payment settles') }}
                    </div>
                    @if (count($payPlan) === 0)
                        <p class="px-3 py-4 text-sm text-chrome-400">{{ __('Enter an amount to see how it is applied.') }}</p>
                    @else
                        <ul class="max-h-56 divide-y divide-chrome-100 overflow-y-auto text-sm">
                            @foreach ($payPlan as $slice)
                                <li wire:key="cspp-{{ $slice['invoice']->id }}" class="flex items-center justify-between gap-3 px-3 py-2">
                                    <span class="font-medium text-chrome-800">{{ $slice['invoice']->reference }}</span>
                                    <span class="text-xs text-chrome-500">{{ $slice['invoice']->issue_date?->isoFormat('DD-MMM-YYYY') }}</span>
                                    <span class="text-xs {{ $slice['settles'] ? 'text-emerald-700' : 'text-amber-700' }}">
                                        {{ $slice['settles'] ? __('Settled in full') : __('Part payment') }}
                                    </span>
                                    <span class="font-semibold text-chrome-800">{{ \App\Erp\Views\ValueFormat::money($slice['amount']) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" wire:click="closePay" class="o-btn-ghost text-sm">{{ __('Close') }}</button>
                    <button type="button" wire:click="savePay" wire:loading.attr="disabled" class="o-btn-primary text-sm">{{ __('Receive payment') }}</button>
                </div>
            </div>
        </div>
    @endif

    {{-- Late payment charge. Raised as an invoice, so it lands in the account's
         outstanding balance and on the statement like any other debt. --}}
    @if ($charging)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-chrome-900/50 p-4"
             x-on:keydown.escape.window="$wire.closeFee()">
            <div class="w-full max-w-md rounded-2xl bg-white p-5 shadow-xl sm:p-6" x-on:click.outside="$wire.closeFee()">
                <h2 class="text-sm font-semibold text-chrome-800">{{ __('Add late fee') }}</h2>
                <p class="mt-1 text-xs text-chrome-500">{{ __('Charged to the account as an invoice, so it shows on the statement and in what is owed.') }}</p>

                <div class="mt-4 space-y-4">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Amount') }}</label>
                            <input type="number" step="0.001" min="0" wire:model="feeAmount" class="o-input w-full">
                            @error('feeAmount') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Charge date') }}</label>
                            <x-date-field wire:model="feeDate" class="o-input w-full" />
                            @error('feeDate') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('For which period') }}</label>
                        <input type="text" wire:model="feePeriod" class="o-input w-full" placeholder="{{ __('e.g. June 2026 — July 2026') }}">
                        @error('feePeriod') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Reason') }}</label>
                        <input type="text" wire:model="feeReason" class="o-input w-full" placeholder="{{ __('e.g. payment delayed past agreed terms') }}">
                    </div>
                </div>

                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" wire:click="closeFee" class="o-btn-ghost text-sm">{{ __('Close') }}</button>
                    <button type="button" wire:click="saveFee" wire:loading.attr="disabled" class="o-btn-primary text-sm">{{ __('Add late fee') }}</button>
                </div>
            </div>
        </div>
    @endif
</div>
