<div class="mx-auto max-w-7xl p-4 sm:p-6">
    <x-page-header :title="__('Receipts')" :subtitle="__('Payments received.')" icon="receipt" accent="primary">
        <x-slot:actions>
            <span class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-50 px-3 py-1.5 text-sm font-medium text-emerald-700 ring-1 ring-emerald-100">
                {{ __('Total') }}: <span class="font-bold">{{ \App\Erp\Views\ValueFormat::money($collectedTotal) }}</span>
            </span>
            @if ($canManage)
                <button type="button" onclick="document.getElementById('import-receipts').classList.toggle('hidden')" class="o-btn-ghost">{{ __('Import') }}</button>
            @endif
            <a href="{{ url('/app/rental/receipt/new') }}" wire:navigate class="o-btn-primary">
                <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10 5a1 1 0 0 1 1 1v3h3a1 1 0 1 1 0 2h-3v3a1 1 0 1 1-2 0v-3H6a1 1 0 1 1 0-2h3V6a1 1 0 0 1 1-1Z"/></svg>
                {{ __('New receipt') }}
            </a>
        </x-slot:actions>
    </x-page-header>

    {{-- Import receipts from a CSV (managers). Direct POST — Hostinger-safe.
         The expected columns are the same shape this screen's own export
         prints. When the Invoice column names an invoice on file, the
         receipt links to it (and its amount_paid/status recompute from the
         sum of its receipts); otherwise the receipt stands alone. --}}
    @if ($canManage)
        <div id="import-receipts" class="mb-4 {{ $errors->any() ? '' : 'hidden' }} rounded-2xl border border-dashed border-chrome-300 bg-white p-4">
            <h3 class="mb-1 text-sm font-semibold text-chrome-800">{{ __('Import receipts (CSV)') }}</h3>
            <p class="mb-3 text-xs text-chrome-500">{{ __('Columns: Reference, Customer, Invoice, Date, Method, Amount. Other columns are ignored. The same customer, date and amount seen before is skipped.') }}</p>
            <form method="POST" action="{{ url('/app/rental/receipt/import') }}" enctype="multipart/form-data" class="flex flex-wrap items-end gap-3">
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

    <div class="mb-4">
        <input type="text" wire:model.live.debounce.300ms="search" placeholder="{{ __('Find receipt (reference or customer)…') }}"
            class="o-input w-full max-w-md text-sm">
    </div>

    @php
        $exportQuery = http_build_query([
            'q' => $search,
            'title' => __('Receipts'),
            // Ticked rows narrow every download to just those.
            'ids' => $this->selectedIdsParam(),
        ]);
    @endphp
    <div class="mb-3 flex flex-wrap items-center gap-2" x-data="listExportCopy">
        <button type="button" x-on:click="copyTable('rental-receipts-table')"
                class="rounded-lg border border-chrome-200 px-3 py-1.5 text-xs font-medium text-chrome-600 transition hover:bg-chrome-50">
            <span x-show="! copied">{{ __('Copy') }}</span>
            <span x-show="copied" x-cloak class="text-emerald-600">{{ __('Copied') }}</span>
        </button>
        <a href="{{ url('/app/rental/receipt/export/csv') }}?{{ $exportQuery }}"
           class="rounded-lg border border-chrome-200 px-3 py-1.5 text-xs font-medium text-chrome-600 transition hover:bg-chrome-50">{{ __('CSV') }}</a>
        <a href="{{ url('/app/rental/receipt/export/excel') }}?{{ $exportQuery }}"
           class="rounded-lg border border-chrome-200 px-3 py-1.5 text-xs font-medium text-chrome-600 transition hover:bg-chrome-50">{{ __('Excel') }}</a>
        <a href="{{ url('/app/rental/receipt/export/pdf') }}?{{ $exportQuery }}"
           class="rounded-lg border border-chrome-200 px-3 py-1.5 text-xs font-medium text-chrome-600 transition hover:bg-chrome-50">{{ __('PDF') }}</a>
        <a href="{{ url('/app/rental/receipt/export/print') }}?{{ $exportQuery }}" target="_blank" rel="noopener"
           class="rounded-lg border border-chrome-200 px-3 py-1.5 text-xs font-medium text-chrome-600 transition hover:bg-chrome-50">{{ __('Print') }}</a>
        @if (count($selected) > 0)
            <span class="ms-1 inline-flex items-center gap-2 rounded-full bg-primary-50 px-3 py-1 text-xs font-medium text-primary-700 ring-1 ring-primary-200">
                {{ __(':count selected', ['count' => count($selected)]) }}
                <button type="button" wire:click="clearSelection" class="font-semibold hover:underline">{{ __('Clear selection') }}</button>
            </span>
        @else
            <span class="ms-1 text-xs text-chrome-400">{{ __('Tick rows to export only those.') }}</span>
        @endif
    </div>

    <div class="overflow-x-auto rounded-2xl bg-white shadow-sm ring-1 ring-chrome-900/[0.06]">
        <table class="w-full min-w-[720px] divide-y divide-chrome-100 text-sm" id="rental-receipts-table">
            <thead class="bg-chrome-50 text-xs font-semibold uppercase tracking-wide text-chrome-500">
                <tr>
                    <th class="w-10 px-4 py-2" data-copy-skip>
                        <input type="checkbox" wire:model.live="selectPage"
                               class="rounded border-chrome-300 text-primary-600 focus:ring-primary-500"
                               aria-label="{{ __('Select all on this page') }}">
                    </th>
                    <th class="px-4 py-2 text-start">{{ __('Reference') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Customer') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Invoice') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Date') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Method') }}</th>
                    <th class="px-4 py-2 text-end">{{ __('Amount') }}</th>
                    <th class="px-4 py-2 text-start" data-copy-skip>{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-chrome-50">
                @forelse ($receipts as $receipt)
                    <tr wire:key="rcpt-{{ $receipt->id }}" class="cursor-pointer hover:bg-chrome-50" data-row-selected="{{ $this->isSelected($receipt->id) ? 1 : 0 }}"
                        onclick="window.location='{{ url('/app/rental/receipt/' . $receipt->id) }}'">
                        {{-- The whole row opens the receipt, so the tick must
                             not also navigate away from the list. --}}
                        <td class="px-4 py-2" data-copy-skip onclick="event.stopPropagation()">
                            <input type="checkbox" wire:model.live="selected" value="{{ $receipt->id }}"
                                   class="rounded border-chrome-300 text-primary-600 focus:ring-primary-500"
                                   aria-label="{{ $receipt->reference }}">
                        </td>
                        <td class="px-4 py-2 font-medium text-chrome-800">{{ $receipt->reference }}</td>
                        <td class="px-4 py-2 text-chrome-700">{{ $receipt->customer?->name ?? '—' }}</td>
                        <td class="px-4 py-2 text-chrome-600">{{ $receipt->invoice?->reference ?? '—' }}</td>
                        <td class="px-4 py-2 text-chrome-600">{{ $receipt->date?->isoFormat('DD-MMM-YYYY') ?? '—' }}</td>
                        <td class="px-4 py-2 text-chrome-600">{{ __(ucfirst($receipt->method)) }}</td>
                        <td class="px-4 py-2 text-end font-semibold text-chrome-800">{{ \App\Erp\Views\ValueFormat::money($receipt->amount) }}</td>
                        <td class="px-4 py-2" data-copy-skip onclick="event.stopPropagation()">
                            {{-- The copy the customer is given — the actual document,
                                 not just this row's own summary of it. --}}
                            <a href="{{ url('/app/rental/receipt/' . $receipt->id . '/download') }}"
                               title="{{ __('Download receipt') }}" aria-label="{{ __('Download receipt') }}"
                               class="inline-flex items-center justify-center rounded-lg border border-chrome-200 p-1.5 text-chrome-600 transition hover:bg-chrome-50">
                                <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10 3a.75.75 0 0 1 .75.75v6.44l1.72-1.72a.75.75 0 1 1 1.06 1.06l-3 3a.75.75 0 0 1-1.06 0l-3-3a.75.75 0 0 1 1.06-1.06l1.72 1.72V3.75A.75.75 0 0 1 10 3ZM3.75 14a.75.75 0 0 1 .75.75v.75h11v-.75a.75.75 0 0 1 1.5 0v1.5a.75.75 0 0 1-.75.75h-12.5a.75.75 0 0 1-.75-.75v-1.5A.75.75 0 0 1 3.75 14Z"/></svg>
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-10 text-center text-sm text-chrome-400">{{ __('No receipts found.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $receipts->links('vendor.pagination.compact') }}</div>
</div>
