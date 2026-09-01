<div class="mx-auto max-w-7xl p-4 sm:p-6">
    <x-page-header :title="__('Receipts')" :subtitle="__('Payments received.')" icon="receipt" accent="indigo">
        <x-slot:actions>
            <span class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-50 px-3 py-1.5 text-sm font-medium text-emerald-700 ring-1 ring-emerald-100">
                {{ __('Total') }}: <span class="font-bold">{{ \App\Erp\Views\ValueFormat::money($collectedTotal) }}</span>
            </span>
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

    <div class="mb-4">
        <input type="text" wire:model.live.debounce.300ms="search" placeholder="{{ __('Find receipt (reference or customer)…') }}"
            class="o-input w-full max-w-md text-sm">
    </div>

    <div class="overflow-x-auto rounded-2xl bg-white shadow-sm ring-1 ring-chrome-900/[0.06]">
        <table class="w-full min-w-[720px] divide-y divide-chrome-100 text-sm">
            <thead class="bg-chrome-50 text-xs font-semibold uppercase tracking-wide text-chrome-500">
                <tr>
                    <th class="px-4 py-2 text-start">{{ __('Reference') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Customer') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Invoice') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Date') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Method') }}</th>
                    <th class="px-4 py-2 text-end">{{ __('Amount') }}</th>
                    <th class="px-4 py-2 text-end">{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-chrome-50">
                @forelse ($receipts as $receipt)
                    <tr wire:key="lrcptrow-{{ $receipt->id }}" class="cursor-pointer hover:bg-chrome-50"
                        onclick="window.location='{{ url('/app/limousine/receipt/' . $receipt->id) }}'">
                        <td class="px-4 py-2 font-medium text-chrome-800">{{ $receipt->reference }}</td>
                        <td class="px-4 py-2 text-chrome-700">{{ $receipt->customer?->name ?? '—' }}</td>
                        <td class="px-4 py-2 text-chrome-600">{{ $receipt->invoice?->reference ?? '—' }}</td>
                        <td class="px-4 py-2 text-chrome-600">{{ $receipt->date?->isoFormat('DD-MMM-YYYY') ?? '—' }}</td>
                        <td class="px-4 py-2 text-chrome-600">{{ __(ucfirst($receipt->method)) }}</td>
                        <td class="px-4 py-2 text-end font-semibold text-chrome-800">{{ \App\Erp\Views\ValueFormat::money($receipt->amount) }}</td>
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
                    <tr><td colspan="7" class="px-4 py-10 text-center text-sm text-chrome-400">{{ __('No receipts found.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $receipts->links() }}</div>

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
