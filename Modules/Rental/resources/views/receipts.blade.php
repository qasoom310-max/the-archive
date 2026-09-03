<div class="mx-auto max-w-7xl p-4 sm:p-6">
    <x-page-header :title="__('Receipts')" :subtitle="__('Payments received.')" icon="receipt" accent="primary">
        <x-slot:actions>
            <span class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-50 px-3 py-1.5 text-sm font-medium text-emerald-700 ring-1 ring-emerald-100">
                {{ __('Total') }}: <span class="font-bold">{{ \App\Erp\Views\ValueFormat::money($collectedTotal) }}</span>
            </span>
            <a href="{{ url('/app/rental/receipt/new') }}" wire:navigate class="o-btn-primary">
                <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10 5a1 1 0 0 1 1 1v3h3a1 1 0 1 1 0 2h-3v3a1 1 0 1 1-2 0v-3H6a1 1 0 1 1 0-2h3V6a1 1 0 0 1 1-1Z"/></svg>
                {{ __('New receipt') }}
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="mb-4">
        <input type="text" wire:model.live.debounce.300ms="search" placeholder="{{ __('Find receipt (reference or customer)…') }}"
            class="o-input w-full max-w-md text-sm">
    </div>

    @php
        $exportQuery = http_build_query([
            'q' => $search,
            'title' => __('Receipts'),
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
    </div>

    <div class="overflow-x-auto rounded-2xl bg-white shadow-sm ring-1 ring-chrome-900/[0.06]">
        <table class="w-full min-w-[720px] divide-y divide-chrome-100 text-sm" id="rental-receipts-table">
            <thead class="bg-chrome-50 text-xs font-semibold uppercase tracking-wide text-chrome-500">
                <tr>
                    <th class="px-4 py-2 text-start">{{ __('Reference') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Customer') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Invoice') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Date') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Method') }}</th>
                    <th class="px-4 py-2 text-end">{{ __('Amount') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-chrome-50">
                @forelse ($receipts as $receipt)
                    <tr wire:key="rcpt-{{ $receipt->id }}" class="cursor-pointer hover:bg-chrome-50"
                        onclick="window.location='{{ url('/app/rental/receipt/' . $receipt->id) }}'">
                        <td class="px-4 py-2 font-medium text-chrome-800">{{ $receipt->reference }}</td>
                        <td class="px-4 py-2 text-chrome-700">{{ $receipt->customer?->name ?? '—' }}</td>
                        <td class="px-4 py-2 text-chrome-600">{{ $receipt->invoice?->reference ?? '—' }}</td>
                        <td class="px-4 py-2 text-chrome-600">{{ $receipt->date?->isoFormat('DD-MMM-YYYY') ?? '—' }}</td>
                        <td class="px-4 py-2 text-chrome-600">{{ __(ucfirst($receipt->method)) }}</td>
                        <td class="px-4 py-2 text-end font-semibold text-chrome-800">{{ \App\Erp\Views\ValueFormat::money($receipt->amount) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-10 text-center text-sm text-chrome-400">{{ __('No receipts found.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $receipts->links() }}</div>
</div>
