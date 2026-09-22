<div class="mx-auto max-w-7xl p-4 sm:p-6">
    <x-page-header :title="__('Invoices')" :subtitle="__('Customer bills.')" icon="doc" accent="indigo">
        <x-slot:actions>
            @if ($canManage)
                <button type="button" onclick="document.getElementById('import-invoices').classList.toggle('hidden')" class="o-btn-ghost">{{ __('Import') }}</button>
            @endif
            <a href="{{ url('/app/limousine/invoice/new') }}" wire:navigate class="o-btn-primary">
                <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10 5a1 1 0 0 1 1 1v3h3a1 1 0 1 1 0 2h-3v3a1 1 0 1 1-2 0v-3H6a1 1 0 1 1 0-2h3V6a1 1 0 0 1 1-1Z"/></svg>
                {{ __('New invoice') }}
            </a>
        </x-slot:actions>
    </x-page-header>

    {{-- Import invoices from a CSV (managers). Direct POST — Hostinger-safe.
         The expected columns are the same shape this screen's own export
         prints. Every row lands as settled history at the figures the old
         system recorded, with no trip behind it. --}}
    @if ($canManage)
        <div id="import-invoices" class="mb-4 {{ $errors->any() ? '' : 'hidden' }} rounded-2xl border border-dashed border-chrome-300 bg-white p-4">
            <h3 class="mb-1 text-sm font-semibold text-chrome-800">{{ __('Import invoices (CSV)') }}</h3>
            <p class="mb-3 text-xs text-chrome-500">{{ __('Columns: Reference, Customer, Issued, Total, Paid, Status. Other columns are ignored. The same customer, issue date and total seen before is skipped.') }}</p>
            <form method="POST" action="{{ url('/app/limousine/invoice/import') }}" enctype="multipart/form-data" class="flex flex-wrap items-end gap-3">
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

    @php $tabs = ['all' => __('All'), 'unpaid' => __('Unpaid'), 'partial' => __('Partial'), 'paid' => __('Paid')]; @endphp
    <div class="mb-4 flex flex-wrap items-center gap-1 border-b border-chrome-200">
        @foreach ($tabs as $key => $label)
            @php $n = $key === 'all' ? $totalCount : (int) $counts->get($key, 0); @endphp
            <button wire:click="$set('tab', '{{ $key }}')"
                class="-mb-px flex items-center gap-1.5 border-b-2 px-4 py-2 text-sm font-medium {{ $tab === $key ? 'border-primary-600 text-primary-700' : 'border-transparent text-chrome-500 hover:text-chrome-800' }}">
                {{ $label }}
                <span class="rounded-full bg-chrome-100 px-1.5 text-[11px] text-chrome-500">{{ $n }}</span>
            </button>
        @endforeach
    </div>

    {{-- Search and a date window: how "this month's invoices for Dadabhai"
         is actually asked for. --}}
    <div class="mb-4 flex flex-wrap items-end gap-3">
        <div class="min-w-[240px] flex-1">
            <label class="mb-1 block text-xs font-medium text-chrome-500">{{ __('Search') }}</label>
            <input type="search" wire:model.live.debounce.300ms="search" class="o-input w-full text-sm"
                   placeholder="{{ __('Invoice, customer, booking…') }}">
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium text-chrome-500">{{ __('Issued from') }}</label>
            <x-date-field wire:model.live="from" class="o-input text-sm" />
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium text-chrome-500">{{ __('Issued to') }}</label>
            <x-date-field wire:model.live="to" class="o-input text-sm" />
        </div>
    </div>

    {{-- One document for a month of work, instead of forty downloads. The
         selection survives paging and re-filtering, so a quarter can be
         gathered a month at a time. --}}
    @if ($selectedCount > 0)
        <div class="mb-4 flex flex-wrap items-center gap-3 rounded-2xl bg-primary-400/15 px-4 py-3 text-sm ring-1 ring-primary-600/20">
            <span class="font-semibold text-chrome-800">
                {{ trans_choice(':count invoice selected|:count invoices selected', $selectedCount, ['count' => $selectedCount]) }}
                @if ($pickedCustomer) · {{ $pickedCustomer }} @endif
            </span>
            @if ($mixedCustomers)
                <span class="text-red-700">{{ __('Pick invoices for one customer — a combined invoice is addressed to one company.') }}</span>
            @elseif ($combinedUrl)
                <a href="{{ $combinedUrl }}" class="o-btn-primary text-sm">{{ __('Combined invoice') }}</a>
            @endif
            <button type="button" wire:click="clearSelection" class="ms-auto text-xs text-chrome-500 hover:underline">{{ __('Clear selection') }}</button>
        </div>
    @endif

    @php
        $exportQuery = http_build_query([
            'tab' => $tab, 'from' => $from, 'to' => $to, 'q' => $search,
            'title' => __('Invoices'),
            // Ticked rows narrow every download to just those.
            'ids' => implode(',', $selected),
        ]);
    @endphp
    <div class="mb-3 flex flex-wrap items-center gap-2" x-data="listExportCopy">
        <button type="button" x-on:click="copyTable('limo-invoices-table')"
                class="rounded-lg border border-chrome-200 px-3 py-1.5 text-xs font-medium text-chrome-600 transition hover:bg-chrome-50">
            <span x-show="! copied">{{ __('Copy') }}</span>
            <span x-show="copied" x-cloak class="text-emerald-600">{{ __('Copied') }}</span>
        </button>
        <a href="{{ url('/app/limousine/invoice/export/csv') }}?{{ $exportQuery }}"
           class="rounded-lg border border-chrome-200 px-3 py-1.5 text-xs font-medium text-chrome-600 transition hover:bg-chrome-50">{{ __('CSV') }}</a>
        <a href="{{ url('/app/limousine/invoice/export/excel') }}?{{ $exportQuery }}"
           class="rounded-lg border border-chrome-200 px-3 py-1.5 text-xs font-medium text-chrome-600 transition hover:bg-chrome-50">{{ __('Excel') }}</a>
        <a href="{{ url('/app/limousine/invoice/export/pdf') }}?{{ $exportQuery }}"
           class="rounded-lg border border-chrome-200 px-3 py-1.5 text-xs font-medium text-chrome-600 transition hover:bg-chrome-50">{{ __('PDF') }}</a>
        <a href="{{ url('/app/limousine/invoice/export/print') }}?{{ $exportQuery }}" target="_blank" rel="noopener"
           class="rounded-lg border border-chrome-200 px-3 py-1.5 text-xs font-medium text-chrome-600 transition hover:bg-chrome-50">{{ __('Print') }}</a>
        {{-- A backup of everything NOT from the historical import — ignores
             the current tab/date/ticked rows on purpose, see LimoInvoiceExportController. --}}
        <a href="{{ url('/app/limousine/invoice/export/csv') }}?live=1"
           class="rounded-lg border border-chrome-200 px-3 py-1.5 text-xs font-medium text-chrome-600 transition hover:bg-chrome-50">{{ __('Live entry data') }}</a>
        @if ($selectedCount === 0)
            <span class="ms-1 text-xs text-chrome-400">{{ __('Tick rows to export only those.') }}</span>
        @endif
    </div>

    <div class="overflow-x-auto rounded-2xl bg-white shadow-sm ring-1 ring-chrome-900/[0.06]">
        <table class="w-full min-w-[720px] divide-y divide-chrome-100 text-sm" id="limo-invoices-table">
            <thead class="bg-chrome-50 text-xs font-semibold uppercase tracking-wide text-chrome-500">
                <tr>
                    <th class="w-10 px-4 py-2" data-copy-skip>
                        <button type="button" wire:click="selectAll" title="{{ __('Select all') }}"
                                class="text-[11px] font-semibold text-primary-700 hover:underline">{{ __('All') }}</button>
                    </th>
                    <th class="px-4 py-2 text-start">{{ __('Reference') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Customer') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Issued') }}</th>
                    <th class="px-4 py-2 text-end">{{ __('Total') }}</th>
                    <th class="px-4 py-2 text-end">{{ __('Paid') }}</th>
                    <th class="px-4 py-2 text-end">{{ __('Balance') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Status') }}</th>
                    <th class="px-4 py-2 text-end">{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-chrome-50">
                @forelse ($invoices as $invoice)
                    @php
                        $sb = ['unpaid' => 'bg-amber-100 text-amber-700', 'partial' => 'bg-sky-100 text-sky-700', 'paid' => 'bg-emerald-100 text-emerald-700'][$invoice->status] ?? 'bg-chrome-200 text-chrome-700';
                    @endphp
                    {{-- Not clickable. A bill is a document, not a workspace —
                         the three things anyone does with one are on the row. --}}
                    <tr wire:key="linv-{{ $invoice->id }}" class="hover:bg-chrome-50" data-row-selected="{{ in_array($invoice->id, $selected) ? 1 : 0 }}">
                        <td class="px-4 py-2" data-copy-skip>
                            <input type="checkbox" value="{{ $invoice->id }}" wire:model.live="selected"
                                   class="size-4 rounded border-chrome-300 text-primary-600 focus:ring-primary-500">
                        </td>
                        <td class="px-4 py-2 font-medium text-chrome-800">{{ $invoice->reference }}</td>
                        <td class="px-4 py-2 text-chrome-700">{{ $invoice->customer?->name ?? '—' }}</td>
                        <td class="px-4 py-2 text-chrome-600">{{ $invoice->issue_date?->isoFormat('DD-MMM-YYYY') ?? '—' }}</td>
                        <td class="px-4 py-2 text-end font-medium text-chrome-800">{{ \App\Erp\Views\ValueFormat::money($invoice->total) }}</td>
                        <td class="px-4 py-2 text-end text-emerald-700">{{ \App\Erp\Views\ValueFormat::money($invoice->amount_paid) }}</td>
                        <td class="px-4 py-2 text-end text-chrome-700">{{ \App\Erp\Views\ValueFormat::money($invoice->balance()) }}</td>
                        <td class="px-4 py-2"><span class="rounded px-2 py-0.5 text-[11px] font-semibold uppercase {{ $sb }}">{{ __(ucfirst($invoice->status)) }}</span></td>
                        <td class="whitespace-nowrap px-4 py-2 text-end">
                            {{-- The copy the customer is given. --}}
                            <a href="{{ url('/app/limousine/invoice/' . $invoice->id . '/download') }}"
                               title="{{ __('Download invoice') }}" aria-label="{{ __('Download invoice') }}"
                               class="inline-flex items-center justify-center rounded-lg border border-chrome-200 p-1.5 text-chrome-600 transition hover:bg-chrome-50">
                                <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10 3a.75.75 0 0 1 .75.75v6.44l1.72-1.72a.75.75 0 1 1 1.06 1.06l-3 3a.75.75 0 0 1-1.06 0l-3-3a.75.75 0 0 1 1.06-1.06l1.72 1.72V3.75A.75.75 0 0 1 10 3ZM3.75 14a.75.75 0 0 1 .75.75v.75h11v-.75a.75.75 0 0 1 1.5 0v1.5a.75.75 0 0 1-.75.75h-12.5a.75.75 0 0 1-.75-.75v-1.5A.75.75 0 0 1 3.75 14Z"/></svg>
                            </a>

                            {{-- Only on a bill raised from a quote and not yet
                                 dispatched: an invoice issued with a booking
                                 already has its trip. --}}
                            @if ($invoice->booking_id === null && $invoice->quotation_id !== null)
                                <button type="button" wire:click="createTrip({{ $invoice->id }})"
                                        title="{{ __('Create trip') }}" aria-label="{{ __('Create trip') }}"
                                        class="ms-1 inline-flex items-center justify-center rounded-lg border border-chrome-200 p-1.5 text-chrome-600 transition hover:bg-chrome-50">
                                    <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path d="M4 5.75A1.75 1.75 0 0 1 5.75 4h4.5a1.75 1.75 0 0 1 1.66 1.2l.35 1.05h1.36c.6 0 1.15.31 1.47.81l1.2 1.9c.17.28.26.6.26.93v2.86c0 .69-.56 1.25-1.25 1.25h-.79a2.25 2.25 0 0 1-4.42 0H8.16a2.25 2.25 0 0 1-4.42 0h-.49C2.56 14 2 13.44 2 12.75v-3.5C2 8.56 2.56 8 3.25 8H4V5.75ZM5.95 13.5a.95.95 0 1 0 1.9 0 .95.95 0 0 0-1.9 0Zm7.15-.95a.95.95 0 1 0 0 1.9.95.95 0 0 0 0-1.9Z"/></svg>
                                </button>
                            @endif

                            @if ($invoice->balance() > 0)
                                <button type="button" wire:click="openCollect({{ $invoice->id }})"
                                        title="{{ __('Receive payment') }}" aria-label="{{ __('Receive payment') }}"
                                        class="ms-1 inline-flex items-center justify-center rounded-lg border border-chrome-200 p-1.5 text-chrome-600 transition hover:bg-chrome-50">
                                    <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path d="M1 5.75C1 4.784 1.784 4 2.75 4h14.5c.966 0 1.75.784 1.75 1.75v8.5A1.75 1.75 0 0 1 17.25 16H2.75A1.75 1.75 0 0 1 1 14.25v-8.5ZM10 7a3 3 0 1 0 0 6 3 3 0 0 0 0-6ZM4.5 8.5a1 1 0 1 1-2 0 1 1 0 0 1 2 0Zm13 3a1 1 0 1 1-2 0 1 1 0 0 1 2 0Z"/></svg>
                                </button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="px-4 py-10 text-center text-sm text-chrome-400">{{ __('No invoices found.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $invoices->links('vendor.pagination.compact') }}</div>

    {{-- Receive payment. Goes through the same service the bookings queue
         uses, so both doors write one receipt and one truth. --}}
    @if ($collecting)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-chrome-900/50 p-4"
             x-on:keydown.escape.window="$wire.closeCollect()">
            <div class="w-full max-w-md rounded-2xl bg-white p-5 shadow-xl sm:p-6" x-on:click.outside="$wire.closeCollect()">
                <h2 class="text-sm font-semibold text-chrome-800">{{ __('Receive payment') }}</h2>
                <p class="mt-1 text-xs text-chrome-500">
                    {{ $collecting->reference }} · {{ __('Balance') }}:
                    <span class="font-semibold">{{ \App\Erp\Views\ValueFormat::money($collectBalance) }}</span>
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
                            <option value="online">{{ __('Online (Tap)') }}</option>
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
