{{--
    The saved bulk invoices. Total, paid and balance are read from the
    invoices inside each one, so they always match the main list.
--}}
@php
    $money = static fn (float $v): string => \App\Erp\Views\ValueFormat::money($v);
    $badge = [
        'unpaid' => ['bg-amber-100 text-amber-700', __('Unpaid')],
        'partial' => ['bg-sky-100 text-sky-700', __('Partial')],
        'paid' => ['bg-emerald-100 text-emerald-700', __('Paid')],
        'cancelled' => ['bg-chrome-200 text-chrome-600', __('Cancelled')],
    ];
    $btn = 'ms-1 inline-flex items-center justify-center rounded-lg border border-chrome-200 p-1.5 text-chrome-600 transition hover:bg-chrome-50';
@endphp

<p class="mb-3 text-xs text-chrome-500">{{ __('To make one: open All, tick the invoices of one customer, then press Make bulk invoice.') }}</p>

<div class="overflow-x-auto rounded-2xl bg-white shadow-sm ring-1 ring-chrome-900/[0.06]" data-bulk-list>
    <table class="w-full min-w-[820px] divide-y divide-chrome-100 text-sm">
        <thead class="bg-chrome-50 text-xs font-semibold uppercase tracking-wide text-chrome-500">
            <tr>
                <th class="px-4 py-2 text-start">{{ __('Reference') }}</th>
                <th class="px-4 py-2 text-start">{{ __('Customer') }}</th>
                <th class="px-4 py-2 text-start">{{ __('Made on') }}</th>
                <th class="px-4 py-2 text-start">{{ __('Invoices') }}</th>
                <th class="px-4 py-2 text-end">{{ __('Total') }}</th>
                <th class="px-4 py-2 text-end">{{ __('Paid') }}</th>
                <th class="px-4 py-2 text-end">{{ __('Balance') }}</th>
                <th class="px-4 py-2 text-start">{{ __('Status') }}</th>
                <th class="px-4 py-2 text-start">{{ __('Sent') }}</th>
                <th class="px-4 py-2 text-end">{{ __('Actions') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-chrome-50">
            @forelse ($bulks as $bulk)
                @php
                    $cancelled = $bulk->isCancelled();
                    [$cls, $label] = $badge[$bulk->status()];
                    $count = $bulk->invoices->count();
                @endphp
                <tr wire:key="lbulk-{{ $bulk->id }}" class="hover:bg-chrome-50 {{ $cancelled ? 'opacity-60' : '' }}" data-bulk-row="{{ $bulk->id }}">
                    <td class="px-4 py-2 font-medium text-chrome-800">{{ $bulk->reference }}</td>
                    <td class="px-4 py-2 text-chrome-700">{{ $bulk->customer?->name ?? '—' }}</td>
                    <td class="px-4 py-2 text-chrome-600">{{ $bulk->issue_date?->isoFormat('DD-MMM-YYYY') ?? '—' }}</td>
                    <td class="px-4 py-2 text-chrome-600">
                        @if ($cancelled)
                            —
                        @else
                            <button type="button" wire:click="showBulk({{ $bulk->id }})" class="font-semibold text-primary-700 hover:underline"
                                    title="{{ $bulk->invoices->pluck('reference')->take(30)->implode(', ') }}">{{ __(':count invoices', ['count' => $count]) }}</button>
                        @endif
                    </td>
                    <td class="px-4 py-2 text-end font-medium text-chrome-800">{{ $cancelled ? '—' : $money($bulk->total()) }}</td>
                    <td class="px-4 py-2 text-end text-emerald-700">{{ $cancelled ? '—' : $money($bulk->paid()) }}</td>
                    <td class="px-4 py-2 text-end text-chrome-700">{{ $cancelled ? '—' : $money($bulk->balance()) }}</td>
                    <td class="px-4 py-2"><span class="rounded px-2 py-0.5 text-[11px] font-semibold uppercase {{ $cls }}">{{ $label }}</span></td>
                    <td class="px-4 py-2 text-xs">
                        @if ($cancelled)
                            —
                        @elseif ($bulk->sent_at)
                            <button type="button" wire:click="toggleBulkSent({{ $bulk->id }})" wire:confirm="{{ __('Take the Sent mark off this bulk invoice?') }}"
                                    class="font-semibold text-emerald-700 hover:underline">{{ $bulk->sent_at->isoFormat('DD-MMM-YYYY') }}</button>
                        @else
                            <button type="button" wire:click="toggleBulkSent({{ $bulk->id }})" data-mark-sent
                                    class="rounded-lg border border-chrome-200 px-2 py-1 font-medium text-chrome-600 transition hover:bg-chrome-50">{{ __('Mark as sent') }}</button>
                        @endif
                    </td>
                    <td class="whitespace-nowrap px-4 py-2 text-end">
                        @unless ($cancelled)
                            <a href="{{ url('/app/limousine/invoice/bulk/' . $bulk->id . '/download') }}"
                               title="{{ __('Download bulk invoice') }}" aria-label="{{ __('Download bulk invoice') }}" class="{{ $btn }}">
                                <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10 3a.75.75 0 0 1 .75.75v6.44l1.72-1.72a.75.75 0 1 1 1.06 1.06l-3 3a.75.75 0 0 1-1.06 0l-3-3a.75.75 0 0 1 1.06-1.06l1.72 1.72V3.75A.75.75 0 0 1 10 3ZM3.75 14a.75.75 0 0 1 .75.75v.75h11v-.75a.75.75 0 0 1 1.5 0v1.5a.75.75 0 0 1-.75.75h-12.5a.75.75 0 0 1-.75-.75v-1.5A.75.75 0 0 1 3.75 14Z"/></svg>
                            </a>
                            @if ($bulk->balance() > 0)
                                <button type="button" wire:click="openBulkPay({{ $bulk->id }})" data-bulk-pay-open
                                        title="{{ __('Receive payment') }}" aria-label="{{ __('Receive payment') }}" class="{{ $btn }}">
                                    <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path d="M1 5.75C1 4.784 1.784 4 2.75 4h14.5c.966 0 1.75.784 1.75 1.75v8.5A1.75 1.75 0 0 1 17.25 16H2.75A1.75 1.75 0 0 1 1 14.25v-8.5ZM10 7a3 3 0 1 0 0 6 3 3 0 0 0 0-6ZM4.5 8.5a1 1 0 1 1-2 0 1 1 0 0 1 2 0Zm13 3a1 1 0 1 1-2 0 1 1 0 0 1 2 0Z"/></svg>
                                </button>
                            @endif
                            @if ($canManage)
                                <button type="button" wire:click="cancelBulk({{ $bulk->id }})"
                                        wire:confirm="{{ __('Cancel this bulk invoice? Its invoices stay as they are and can go in a new bulk invoice.') }}"
                                        title="{{ __('Cancel bulk invoice') }}" aria-label="{{ __('Cancel bulk invoice') }}" class="{{ $btn }} hover:text-red-600">
                                    <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z"/></svg>
                                </button>
                            @endif
                        @endunless
                    </td>
                </tr>
            @empty
                <tr><td colspan="10" class="px-4 py-10 text-center text-sm text-chrome-400">{{ __('No bulk invoices yet.') }}</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">{{ $bulks->links('vendor.pagination.compact') }}</div>
