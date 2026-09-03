<div class="mx-auto max-w-7xl p-4 sm:p-6">
    <x-page-header :title="__('Receipts')" :subtitle="__('Payments received.')" icon="receipt" accent="indigo">
        <x-slot:actions>
            <span class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-50 px-3 py-1.5 text-sm font-medium text-emerald-700 ring-1 ring-emerald-100">
                {{ __('Total') }}: <span class="font-bold">{{ \App\Erp\Views\ValueFormat::money($collectedTotal) }}</span>
            </span>
            @if ($canManage)
                <button type="button" onclick="document.getElementById('import-receipts').classList.toggle('hidden')" class="o-btn-ghost">{{ __('Import') }}</button>
            @endif
            {{-- Receipts write themselves when money is taken on a booking, so a
                 hand-made one is a correction, not the normal way in. Kept for the
                 owner only: two receipts for the same payment is a hard mistake to
                 spot afterwards. ReceiptForm enforces the same rule server-side. --}}
            @if ($canCreateManually)
                <a href="{{ url('/app/limousine/receipt/new') }}" wire:navigate class="o-btn-primary">
                    <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10 5a1 1 0 0 1 1 1v3h3a1 1 0 1 1 0 2h-3v3a1 1 0 1 1-2 0v-3H6a1 1 0 1 1 0-2h3V6a1 1 0 0 1 1-1Z"/></svg>
                    {{ __('New receipt') }}
                </a>
            @endif
        </x-slot:actions>
    </x-page-header>

    {{-- Import receipts from a CSV (managers). Direct POST — Hostinger-safe.
         The expected columns are the same shape this screen's own export
         prints. Historical money lands already confirmed — no accountant
         queue for years-old trips. --}}
    @if ($canManage)
        <div id="import-receipts" class="mb-4 {{ $errors->any() ? '' : 'hidden' }} rounded-2xl border border-dashed border-chrome-300 bg-white p-4">
            <h3 class="mb-1 text-sm font-semibold text-chrome-800">{{ __('Import receipts (CSV)') }}</h3>
            <p class="mb-3 text-xs text-chrome-500">{{ __('Columns: Reference, Customer, Invoice, Date, Method, Amount. Other columns are ignored. The same customer, date and amount seen before is skipped.') }}</p>
            <form method="POST" action="{{ url('/app/limousine/receipt/import') }}" enctype="multipart/form-data" class="flex flex-wrap items-end gap-3">
                @csrf
                <input type="file" name="file" accept=".csv,text/csv,text/plain" required class="text-sm">
                <button type="submit" class="o-btn-primary text-sm">{{ __('Import') }}</button>
            </form>
            @error('file')<p class="mt-2 text-xs font-medium text-red-600">{{ $message }}</p>@enderror
            @if (session('toast'))
                <p class="mt-2 text-xs font-medium text-emerald-600">{{ session('toast') }}</p>
            @endif
        </div>
    @endif

    {{-- The accountant's desk is the first tab: what was written but not yet
         vouched for. Cash is checked against the drawer; bank methods against
         the company statement. --}}
    @php $tabs = ['to_confirm' => __('To confirm'), 'confirmed' => __('Confirmed'), 'all' => __('All')]; @endphp
    <div class="mb-4 flex flex-wrap items-center gap-1 border-b border-chrome-200">
        @foreach ($tabs as $key => $label)
            <button wire:click="$set('tab', '{{ $key }}')"
                class="-mb-px flex items-center gap-1.5 border-b-2 px-4 py-2 text-sm font-medium {{ $tab === $key ? 'border-primary-600 text-primary-700' : 'border-transparent text-chrome-500 hover:text-chrome-800' }}">
                {{ $label }}
                @if ($key === 'to_confirm' && $pendingCount > 0)
                    <span class="rounded-full bg-amber-100 px-1.5 text-[11px] font-semibold text-amber-700">{{ $pendingCount }}</span>
                @endif
            </button>
        @endforeach
    </div>

    <div class="mb-4 flex flex-wrap items-center gap-3">
        <input type="text" wire:model.live.debounce.300ms="search" placeholder="{{ __('Find receipt (reference or customer)…') }}"
            class="o-input w-full max-w-md text-sm">
        <select wire:model.live="method" class="o-input text-sm">
            <option value="">{{ __('All methods') }}</option>
            <option value="cash">{{ __('Cash') }}</option>
            <option value="card">{{ __('Card') }}</option>
            <option value="benefit">{{ __('Benefit') }}</option>
            <option value="transfer">{{ __('Transfer') }}</option>
        </select>
    </div>

    @php
        $exportQuery = http_build_query([
            'tab' => $tab, 'method' => $method, 'q' => $search,
            'title' => __('Receipts'),
        ]);
    @endphp
    <div class="mb-3 flex flex-wrap items-center gap-2" x-data="listExportCopy">
        <button type="button" x-on:click="copyTable('limo-receipts-table')"
                class="rounded-lg border border-chrome-200 px-3 py-1.5 text-xs font-medium text-chrome-600 transition hover:bg-chrome-50">
            <span x-show="! copied">{{ __('Copy') }}</span>
            <span x-show="copied" x-cloak class="text-emerald-600">{{ __('Copied') }}</span>
        </button>
        <a href="{{ url('/app/limousine/receipt/export/csv') }}?{{ $exportQuery }}"
           class="rounded-lg border border-chrome-200 px-3 py-1.5 text-xs font-medium text-chrome-600 transition hover:bg-chrome-50">{{ __('CSV') }}</a>
        <a href="{{ url('/app/limousine/receipt/export/excel') }}?{{ $exportQuery }}"
           class="rounded-lg border border-chrome-200 px-3 py-1.5 text-xs font-medium text-chrome-600 transition hover:bg-chrome-50">{{ __('Excel') }}</a>
        <a href="{{ url('/app/limousine/receipt/export/pdf') }}?{{ $exportQuery }}"
           class="rounded-lg border border-chrome-200 px-3 py-1.5 text-xs font-medium text-chrome-600 transition hover:bg-chrome-50">{{ __('PDF') }}</a>
        <a href="{{ url('/app/limousine/receipt/export/print') }}?{{ $exportQuery }}" target="_blank" rel="noopener"
           class="rounded-lg border border-chrome-200 px-3 py-1.5 text-xs font-medium text-chrome-600 transition hover:bg-chrome-50">{{ __('Print') }}</a>
    </div>

    @if ($tab === 'to_confirm')
        {{-- One row per PAYMENT: a bulk settlement shows as its lump, because
             nobody should close a lump sum forty receipts at a time. --}}
        <div class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-chrome-900/[0.06]">
            @if ($pending->isEmpty())
                <p class="px-4 py-12 text-center text-sm text-chrome-400">{{ __('Nothing waiting to be confirmed.') }}</p>
            @else
                <ul class="divide-y divide-chrome-100">
                    @foreach ($pending as $group)
                        @php
                            $first = $group->first();
                            $isBatch = $group->count() > 1;
                            $sum = $group->sum('amount');
                        @endphp
                        <li wire:key="pend-{{ $first->batch_id ?? $first->id }}" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3">
                            <div class="min-w-40">
                                <div class="font-medium text-chrome-800">
                                    {{ $isBatch ? __('Bulk payment') : $first->reference }}
                                </div>
                                <div class="text-xs text-chrome-400">
                                    @if ($isBatch)
                                        {{ __(':count receipts', ['count' => $group->count()]) }} ·
                                        {{ $group->pluck('invoice.reference')->filter()->implode(', ') }}
                                    @else
                                        {{ $first->invoice?->reference }}
                                    @endif
                                </div>
                            </div>
                            <span class="text-chrome-700">{{ $first->customer?->name ?? '—' }}</span>
                            <span class="text-chrome-500">{{ $first->date?->isoFormat('DD-MMM-YYYY') }}</span>
                            <span class="rounded bg-chrome-100 px-2 py-0.5 text-[11px] font-semibold uppercase text-chrome-600">{{ __(ucfirst($first->method)) }}</span>
                            <span class="ms-auto font-semibold text-chrome-800">{{ \App\Erp\Views\ValueFormat::money($sum) }}</span>
                            @if ($canConfirm)
                                <button type="button"
                                        wire:click="{{ $isBatch ? 'openConfirmBatch(\'' . $first->batch_id . '\')' : 'openConfirm(' . $first->id . ')' }}"
                                        class="o-btn-primary text-sm">
                                    {{ $first->isCash() ? __('Confirm cash received') : __('Confirm — seen on statement') }}
                                </button>
                            @else
                                <span class="text-xs text-chrome-400">{{ __('Awaiting the accountant') }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @else
    <div class="overflow-x-auto rounded-2xl bg-white shadow-sm ring-1 ring-chrome-900/[0.06]">
        <table class="w-full min-w-[720px] divide-y divide-chrome-100 text-sm" id="limo-receipts-table">
            <thead class="bg-chrome-50 text-xs font-semibold uppercase tracking-wide text-chrome-500">
                <tr>
                    <th class="px-4 py-2 text-start">{{ __('Reference') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Customer') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Invoice') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Date') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Method') }}</th>
                    <th class="px-4 py-2 text-end">{{ __('Amount') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Confirmed') }}</th>
                    <th class="px-4 py-2 text-end">{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-chrome-50">
                @forelse ($receipts as $receipt)
                    {{-- Not clickable. A receipt is a record of money already
                         taken, so there is nothing to open it FOR — the two
                         things anyone needs are Download and Send. --}}
                    <tr wire:key="lrcptrow-{{ $receipt->id }}" class="hover:bg-chrome-50">
                        <td class="px-4 py-2 font-medium text-chrome-800">{{ $receipt->reference }}</td>
                        <td class="px-4 py-2 text-chrome-700">{{ $receipt->customer?->name ?? '—' }}</td>
                        <td class="px-4 py-2 text-chrome-600">{{ $receipt->invoice?->reference ?? '—' }}</td>
                        <td class="px-4 py-2 text-chrome-600">{{ $receipt->date?->isoFormat('DD-MMM-YYYY') ?? '—' }}</td>
                        <td class="px-4 py-2 text-chrome-600">{{ __(ucfirst($receipt->method)) }}</td>
                        <td class="px-4 py-2 text-end font-semibold text-chrome-800">{{ \App\Erp\Views\ValueFormat::money($receipt->amount) }}</td>
                        <td class="px-4 py-2">
                            @if ($receipt->isConfirmed())
                                {{-- Who vouched, and — for a bank method — the
                                     statement day it was found under. --}}
                                <span class="rounded bg-emerald-100 px-2 py-0.5 text-[11px] font-semibold uppercase text-emerald-700"
                                      title="{{ $receipt->confirmed_by }}{{ $receipt->statement_date ? ' · ' . __('Statement') . ' ' . $receipt->statement_date->isoFormat('DD-MMM-YYYY') : '' }}">
                                    {{ __('Confirmed') }}
                                </span>
                                @if ($canConfirm)
                                    <button type="button" wire:click="unconfirm({{ $receipt->id }})"
                                            wire:confirm="{{ __('Take back this confirmation?') }}"
                                            class="ms-1 text-[11px] text-chrome-400 hover:underline">{{ __('Undo') }}</button>
                                @endif
                            @else
                                <span class="rounded bg-amber-100 px-2 py-0.5 text-[11px] font-semibold uppercase text-amber-700">{{ __('Unconfirmed') }}</span>
                            @endif
                        </td>
                        {{-- The customer's copy. stopPropagation so fetching it
                             doesn't also open the row's edit form. --}}
                        <td class="whitespace-nowrap px-4 py-2 text-end" onclick="event.stopPropagation()">
                            <a href="{{ url('/app/limousine/receipt/' . $receipt->id . '/download') }}"
                               class="inline-flex items-center gap-1 rounded-lg border border-chrome-200 px-2.5 py-1 text-xs font-medium text-chrome-600 transition hover:bg-chrome-50">
                                <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor"><path d="M10 3a.75.75 0 0 1 .75.75v6.44l1.72-1.72a.75.75 0 1 1 1.06 1.06l-3 3a.75.75 0 0 1-1.06 0l-3-3a.75.75 0 0 1 1.06-1.06l1.72 1.72V3.75A.75.75 0 0 1 10 3ZM3.75 14a.75.75 0 0 1 .75.75v.75h11v-.75a.75.75 0 0 1 1.5 0v1.5a.75.75 0 0 1-.75.75h-12.5a.75.75 0 0 1-.75-.75v-1.5A.75.75 0 0 1 3.75 14Z"/></svg>
                                {{ __('Download') }}
                            </a>
                            <button type="button" wire:click="openSend({{ $receipt->id }})"
                                    class="ms-1 inline-flex items-center gap-1 rounded-lg border border-chrome-200 px-2.5 py-1 text-xs font-medium text-chrome-600 transition hover:bg-chrome-50">
                                {{ __('Send') }}
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-10 text-center text-sm text-chrome-400">{{ __('No receipts found.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @endif

    @if ($receipts !== null)
        <div class="mt-4">{{ $receipts->links() }}</div>
    @endif

    {{-- The confirmation itself. Cash needs only the vouching; a bank method
         records the statement date it was found under, because "I saw it"
         without saying where is not something anyone can check later. --}}
    @if ($confirmingId !== null || $confirmingBatch !== null)
        @php
            // A lump of cash is vouched for like cash; only bank money asks for
            // the statement date it was found under.
            $isCashConfirm = $confirmingReceipt !== null ? $confirmingReceipt->isCash() : $confirmingBatchIsCash;
        @endphp
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-chrome-900/50 p-4"
             x-on:keydown.escape.window="$wire.closeConfirm()">
            <div class="w-full max-w-md rounded-2xl bg-white p-5 shadow-xl sm:p-6" x-on:click.outside="$wire.closeConfirm()">
                <h2 class="text-sm font-semibold text-chrome-800">{{ __('Confirm payment') }}</h2>
                <p class="mt-1 text-xs text-chrome-500">
                    {{ $isCashConfirm
                        ? __('You are vouching that this cash reached the company.')
                        : __('Confirm only after finding the amount on the company statement.') }}
                </p>

                @unless ($isCashConfirm)
                    <div class="mt-4">
                        <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Statement date') }}</label>
                        <x-date-field wire:model="statementDate" class="o-input w-full" />
                        @error('statementDate') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                @endunless

                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" wire:click="closeConfirm" class="o-btn-ghost text-sm">{{ __('Close') }}</button>
                    <button type="button" wire:click="saveConfirm" wire:loading.attr="disabled" class="o-btn-primary text-sm">{{ __('Confirm payment') }}</button>
                </div>
            </div>
        </div>
    @endif

    {{-- Send: the address is shown rather than used silently. The one on file
         belongs to whoever placed the booking, which is often not whoever
         paid — so the office confirms it before it goes. --}}
    @if ($sendingId !== null)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-chrome-900/50 p-4"
             x-on:keydown.escape.window="$wire.closeSend()">
            <div class="w-full max-w-md rounded-2xl bg-white p-5 shadow-xl sm:p-6" x-on:click.outside="$wire.closeSend()">
                <h2 class="text-sm font-semibold text-chrome-800">{{ __('Send receipt') }}</h2>
                <p class="mt-1 text-xs text-chrome-500">{{ __('The receipt goes out as a PDF attachment.') }}</p>

                <div class="mt-4">
                    <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Email') }}</label>
                    <input type="email" wire:model="sendEmail" class="o-input w-full" placeholder="name@example.com">
                    @error('sendEmail') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" wire:click="closeSend" class="o-btn-ghost text-sm">{{ __('Close') }}</button>
                    <button type="button" wire:click="sendReceipt" wire:loading.attr="disabled" class="o-btn-primary text-sm">{{ __('Send') }}</button>
                </div>
            </div>
        </div>
    @endif
</div>
