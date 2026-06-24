<div class="mx-auto max-w-7xl p-4 sm:p-6">
    <div class="mb-4 flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold text-chrome-900">{{ __('Receipts') }}</h1>
            <p class="text-sm text-chrome-500">{{ __('Payments received.') }} · {{ __('Total') }}: <span class="font-semibold text-chrome-700">{{ \App\Erp\Views\ValueFormat::money($collectedTotal) }}</span></p>
        </div>
        <a href="{{ url('/app/rental/receipt/new') }}" wire:navigate class="o-btn-primary">
            <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10 5a1 1 0 0 1 1 1v3h3a1 1 0 1 1 0 2h-3v3a1 1 0 1 1-2 0v-3H6a1 1 0 1 1 0-2h3V6a1 1 0 0 1 1-1Z"/></svg>
            {{ __('New receipt') }}
        </a>
    </div>

    <div class="mb-4">
        <input type="text" wire:model.live.debounce.300ms="search" placeholder="{{ __('Find receipt (reference or customer)…') }}"
            class="o-input w-full max-w-md text-sm">
    </div>

    <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-chrome-900/5">
        <table class="min-w-full divide-y divide-chrome-100 text-sm">
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
                        <td class="px-4 py-2 text-chrome-600">{{ $receipt->date?->isoFormat('MMM D, YYYY') ?? '—' }}</td>
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
