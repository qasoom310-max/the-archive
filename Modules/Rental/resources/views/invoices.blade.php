<div class="mx-auto max-w-7xl p-4 sm:p-6">
    <x-page-header :title="__('Invoices')" :subtitle="__('Customer bills.')" icon="doc" accent="primary">
        <x-slot:actions>
            <a href="{{ url('/app/rental/invoice/new') }}" wire:navigate class="o-btn-primary">
                <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10 5a1 1 0 0 1 1 1v3h3a1 1 0 1 1 0 2h-3v3a1 1 0 1 1-2 0v-3H6a1 1 0 1 1 0-2h3V6a1 1 0 0 1 1-1Z"/></svg>
                {{ __('New invoice') }}
            </a>
        </x-slot:actions>
    </x-page-header>

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

    <div class="overflow-x-auto rounded-2xl bg-white shadow-sm ring-1 ring-chrome-900/[0.06]">
        <table class="w-full min-w-[720px] divide-y divide-chrome-100 text-sm">
            <thead class="bg-chrome-50 text-xs font-semibold uppercase tracking-wide text-chrome-500">
                <tr>
                    <th class="px-4 py-2 text-start">{{ __('Reference') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Customer') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Issued') }}</th>
                    <th class="px-4 py-2 text-end">{{ __('Total') }}</th>
                    <th class="px-4 py-2 text-end">{{ __('Paid') }}</th>
                    <th class="px-4 py-2 text-end">{{ __('Balance') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Status') }}</th>
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
                    <tr wire:key="inv-{{ $invoice->id }}" class="cursor-pointer hover:bg-chrome-50"
                        onclick="window.location='{{ url('/app/rental/invoice/' . $invoice->id) }}'">
                        <td class="px-4 py-2 font-medium text-chrome-800">{{ $invoice->reference }}</td>
                        <td class="px-4 py-2 text-chrome-700">{{ $invoice->customer?->name ?? '—' }}</td>
                        <td class="px-4 py-2 text-chrome-600">{{ $invoice->issue_date?->isoFormat('DD-MMM-YYYY') ?? '—' }}</td>
                        <td class="px-4 py-2 text-end font-medium text-chrome-800">{{ \App\Erp\Views\ValueFormat::money($invoice->total) }}</td>
                        <td class="px-4 py-2 text-end text-emerald-700">{{ \App\Erp\Views\ValueFormat::money($invoice->amount_paid) }}</td>
                        <td class="px-4 py-2 text-end text-chrome-700">{{ \App\Erp\Views\ValueFormat::money($invoice->balance()) }}</td>
                        <td class="px-4 py-2"><span class="rounded px-2 py-0.5 text-[11px] font-semibold uppercase {{ $sb }}">{{ __(ucfirst($invoice->status)) }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-10 text-center text-sm text-chrome-400">{{ __('No invoices found.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $invoices->links() }}</div>
</div>
