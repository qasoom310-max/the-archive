<div class="mx-auto max-w-7xl p-4 sm:p-6">
    <x-page-header :title="__('Invoices')" :subtitle="__('Customer bills.')" icon="doc" accent="primary">
        <x-slot:actions>
            @if ($canManage)
                <button type="button" onclick="document.getElementById('import-invoices').classList.toggle('hidden')" class="o-btn-ghost">{{ __('Import') }}</button>
            @endif
            <a href="{{ url('/app/rental/invoice/new') }}" wire:navigate class="o-btn-primary">
                <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10 5a1 1 0 0 1 1 1v3h3a1 1 0 1 1 0 2h-3v3a1 1 0 1 1-2 0v-3H6a1 1 0 1 1 0-2h3V6a1 1 0 0 1 1-1Z"/></svg>
                {{ __('New invoice') }}
            </a>
        </x-slot:actions>
    </x-page-header>

    {{-- Import invoices from a CSV (managers). Direct POST — Hostinger-safe.
         The expected columns are the same shape this screen's own export
         prints. Every row lands as settled history at the figures the old
         system recorded, with no order behind it. --}}
    @if ($canManage)
        <div id="import-invoices" class="mb-4 {{ $errors->any() ? '' : 'hidden' }} rounded-2xl border border-dashed border-chrome-300 bg-white p-4">
            <h3 class="mb-1 text-sm font-semibold text-chrome-800">{{ __('Import invoices (CSV)') }}</h3>
            <p class="mb-3 text-xs text-chrome-500">{{ __('Columns: Reference, Customer, Issued, Total, Paid, Status. Other columns are ignored. The same customer, issue date and total seen before is skipped.') }}</p>
            <form method="POST" action="{{ url('/app/rental/invoice/import') }}" enctype="multipart/form-data" class="flex flex-wrap items-end gap-3">
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

    @php
        $tabs = [
            'all' => __('All'),
            'unpaid' => __('Unpaid'),
            'partial' => __('Partial'),
            'paid' => __('Paid'),
        ];
    @endphp
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

    @php
        $exportQuery = http_build_query([
            'tab' => $tab,
            'title' => __('Invoices'),
            // Ticked rows narrow every download to just those.
            'ids' => $this->selectedIdsParam(),
        ]);
    @endphp
    <div class="mb-3 flex flex-wrap items-center gap-2" x-data="listExportCopy">
        <button type="button" x-on:click="copyTable('rental-invoices-table')"
                class="rounded-lg border border-chrome-200 px-3 py-1.5 text-xs font-medium text-chrome-600 transition hover:bg-chrome-50">
            <span x-show="! copied">{{ __('Copy') }}</span>
            <span x-show="copied" x-cloak class="text-emerald-600">{{ __('Copied') }}</span>
        </button>
        <a href="{{ url('/app/rental/invoice/export/csv') }}?{{ $exportQuery }}"
           class="rounded-lg border border-chrome-200 px-3 py-1.5 text-xs font-medium text-chrome-600 transition hover:bg-chrome-50">{{ __('CSV') }}</a>
        <a href="{{ url('/app/rental/invoice/export/excel') }}?{{ $exportQuery }}"
           class="rounded-lg border border-chrome-200 px-3 py-1.5 text-xs font-medium text-chrome-600 transition hover:bg-chrome-50">{{ __('Excel') }}</a>
        <a href="{{ url('/app/rental/invoice/export/pdf') }}?{{ $exportQuery }}"
           class="rounded-lg border border-chrome-200 px-3 py-1.5 text-xs font-medium text-chrome-600 transition hover:bg-chrome-50">{{ __('PDF') }}</a>
        <a href="{{ url('/app/rental/invoice/export/print') }}?{{ $exportQuery }}" target="_blank" rel="noopener"
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
        <table class="w-full min-w-[720px] divide-y divide-chrome-100 text-sm" id="rental-invoices-table">
            <thead class="bg-chrome-50 text-xs font-semibold uppercase tracking-wide text-chrome-500">
                <tr>
                    <th class="w-10 px-4 py-2" data-copy-skip>
                        <input type="checkbox" wire:model.live="selectPage"
                               class="rounded border-chrome-300 text-primary-600 focus:ring-primary-500"
                               aria-label="{{ __('Select all on this page') }}">
                    </th>
                    <th class="px-4 py-2 text-start">{{ __('Reference') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Customer') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Issued') }}</th>
                    <th class="px-4 py-2 text-end">{{ __('Total') }}</th>
                    <th class="px-4 py-2 text-end">{{ __('Paid') }}</th>
                    <th class="px-4 py-2 text-end">{{ __('Balance') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Status') }}</th>
                    <th class="px-4 py-2 text-start" data-copy-skip>{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-chrome-50">
                @forelse ($invoices as $invoice)
                    @php
                        $sb = [
                            'unpaid' => 'bg-amber-100 text-amber-700',
                            'partial' => 'bg-sky-100 text-sky-700',
                            'paid' => 'bg-emerald-100 text-emerald-700',
                        ][$invoice->status] ?? 'bg-chrome-200 text-chrome-700';
                    @endphp
                    <tr wire:key="inv-{{ $invoice->id }}" class="cursor-pointer hover:bg-chrome-50" data-row-selected="{{ $this->isSelected($invoice->id) ? 1 : 0 }}"
                        onclick="window.location='{{ url('/app/rental/invoice/' . $invoice->id) }}'">
                        <td class="px-4 py-2" data-copy-skip onclick="event.stopPropagation()">
                            <input type="checkbox" wire:model.live="selected" value="{{ $invoice->id }}"
                                   class="rounded border-chrome-300 text-primary-600 focus:ring-primary-500"
                                   aria-label="{{ $invoice->reference }}">
                        </td>
                        <td class="px-4 py-2 font-medium text-chrome-800">{{ $invoice->reference }}</td>
                        <td class="px-4 py-2 text-chrome-700">{{ $invoice->customer?->name ?? '—' }}</td>
                        <td class="px-4 py-2 text-chrome-600">{{ $invoice->issue_date?->isoFormat('DD-MMM-YYYY') ?? '—' }}</td>
                        <td class="px-4 py-2 text-end font-medium text-chrome-800">{{ \App\Erp\Views\ValueFormat::money($invoice->total) }}</td>
                        <td class="px-4 py-2 text-end text-emerald-700">{{ \App\Erp\Views\ValueFormat::money($invoice->amount_paid) }}</td>
                        <td class="px-4 py-2 text-end text-chrome-700">{{ \App\Erp\Views\ValueFormat::money($invoice->balance()) }}</td>
                        <td class="px-4 py-2"><span class="rounded px-2 py-0.5 text-[11px] font-semibold uppercase {{ $sb }}">{{ __(ucfirst($invoice->status)) }}</span></td>
                        <td class="px-4 py-2" data-copy-skip onclick="event.stopPropagation()">
                            {{-- The copy the customer is given — the actual document,
                                 not just this row's own summary of it. --}}
                            <a href="{{ url('/app/rental/invoice/' . $invoice->id . '/download') }}"
                               title="{{ __('Download invoice') }}" aria-label="{{ __('Download invoice') }}"
                               class="inline-flex items-center justify-center rounded-lg border border-chrome-200 p-1.5 text-chrome-600 transition hover:bg-chrome-50">
                                <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10 3a.75.75 0 0 1 .75.75v6.44l1.72-1.72a.75.75 0 1 1 1.06 1.06l-3 3a.75.75 0 0 1-1.06 0l-3-3a.75.75 0 0 1 1.06-1.06l1.72 1.72V3.75A.75.75 0 0 1 10 3ZM3.75 14a.75.75 0 0 1 .75.75v.75h11v-.75a.75.75 0 0 1 1.5 0v1.5a.75.75 0 0 1-.75.75h-12.5a.75.75 0 0 1-.75-.75v-1.5A.75.75 0 0 1 3.75 14Z"/></svg>
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="px-4 py-10 text-center text-sm text-chrome-400">{{ __('No invoices found.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $invoices->links('vendor.pagination.compact') }}</div>
</div>
