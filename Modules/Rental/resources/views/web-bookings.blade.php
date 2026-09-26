{{--
    Bookings taken on the website, waiting to become rental orders.

    Two tabs, Pending and Complete, as the old system had them. The columns are
    what the website reliably sends; everything it sent is in the View panel,
    including the raw request, because the site can carry fields this screen has
    no column for and a customer's own words should not be unreachable.
--}}
<div class="mx-auto max-w-7xl p-4 sm:p-6">

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-lg font-bold text-chrome-900">{{ __('Web bookings') }}</h1>
            <p class="text-sm text-chrome-500">{{ __('Bookings taken on the website, ready to become orders.') }}</p>
        </div>
        <a href="{{ url('/app/rental') }}" wire:navigate
           class="rounded-lg border border-chrome-200 px-3 py-1.5 text-xs font-medium text-chrome-700 transition hover:bg-chrome-50">
            {{ __('Back to Rent A Car') }}
        </a>
    </div>

    @if ($flash !== '')
        <p class="mb-3 rounded-xl bg-emerald-50 px-4 py-2 text-sm text-emerald-800 ring-1 ring-emerald-200">{{ $flash }}</p>
    @endif
    @if ($error !== '')
        <p class="mb-3 rounded-xl bg-red-50 px-4 py-2 text-sm text-red-800 ring-1 ring-red-200">{{ $error }}</p>
    @endif

    <div class="mb-3 flex flex-wrap items-center gap-2">
        @foreach ([
            \Modules\Rental\Models\RentalWebBooking::STATUS_PENDING => __('Pending'),
            \Modules\Rental\Models\RentalWebBooking::STATUS_COMPLETE => __('Complete'),
            'all' => __('All'),
        ] as $value => $label)
            <button type="button" wire:click="$set('tab', '{{ $value }}')"
                    class="rounded-lg px-3 py-1.5 text-xs font-medium transition {{ $tab === $value ? 'bg-primary-400 text-chrome-900' : 'border border-chrome-200 text-chrome-700 hover:bg-chrome-50' }}">
                {{ $label }}
                @if ($value === \Modules\Rental\Models\RentalWebBooking::STATUS_PENDING && $pendingCount > 0)
                    <span class="ms-1 rounded-full bg-chrome-900/10 px-1.5">{{ $pendingCount }}</span>
                @endif
            </button>
        @endforeach

        <input type="search" wire:model.live.debounce.300ms="search"
               placeholder="{{ __('Search name, phone, email…') }}"
               class="o-input ms-auto w-full max-w-xs text-sm">
    </div>

    <div class="overflow-x-auto rounded-2xl bg-white shadow-sm ring-1 ring-chrome-900/[0.06]">
        <table class="w-full min-w-[46rem] divide-y divide-chrome-100 text-xs">
            <thead class="bg-chrome-50 text-xs font-semibold uppercase tracking-wide text-chrome-700">
                <tr>
                    <th class="whitespace-nowrap px-3 py-2 text-start">{{ __('Reference') }}</th>
                    <th class="px-3 py-2 text-start">{{ __('Name') }}</th>
                    <th class="whitespace-nowrap px-3 py-2 text-start">{{ __('Phone') }}</th>
                    <th class="px-3 py-2 text-start">{{ __('Product') }}</th>
                    <th class="whitespace-nowrap px-3 py-2 text-start">{{ __('From date') }}</th>
                    <th class="whitespace-nowrap px-3 py-2 text-start">{{ __('To date') }}</th>
                    <th class="whitespace-nowrap px-3 py-2 text-end">{{ __('Total') }}</th>
                    <th class="whitespace-nowrap px-3 py-2 text-start">{{ __('Status') }}</th>
                    <th class="whitespace-nowrap px-3 py-2 text-end">{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-chrome-100">
                @forelse ($bookings as $b)
                    <tr class="hover:bg-chrome-50">
                        <td class="whitespace-nowrap px-3 py-2 font-medium text-chrome-900" dir="ltr">{{ $b->source_reference }}</td>
                        <td class="px-3 py-2 text-chrome-900">{{ $b->customerName() }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-chrome-900" dir="ltr">{{ $b->phone ?: '—' }}</td>
                        <td class="max-w-[18rem] truncate px-3 py-2 text-chrome-600" title="{{ $b->product }}">{{ $b->product ?: '—' }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-chrome-900">{{ $b->pickup_at?->isoFormat('DD-MMM-YY HH:mm') ?? '—' }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-chrome-900">{{ $b->dropoff_at?->isoFormat('DD-MMM-YY HH:mm') ?? '—' }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-end font-medium text-chrome-900">
                            {{ $b->total !== null ? \App\Erp\Views\ValueFormat::money($b->total) : '—' }}
                        </td>
                        <td class="whitespace-nowrap px-3 py-2">
                            <span class="rounded px-2 py-0.5 text-[11px] font-semibold uppercase {{ $b->isPending() ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700' }}">
                                {{ __(ucfirst($b->status)) }}
                            </span>
                            @if ($b->isPaidOnline())
                                <span class="ms-1 rounded bg-emerald-100 px-2 py-0.5 text-[11px] font-semibold uppercase text-emerald-700">{{ __('Paid') }}</span>
                            @endif
                        </td>
                        <td class="whitespace-nowrap px-3 py-2 text-end">
                            <button type="button" wire:click="view({{ $b->id }})"
                                    class="rounded-lg border border-chrome-200 px-2.5 py-1 text-xs font-medium text-chrome-700 transition hover:bg-chrome-50">
                                {{ __('View') }}
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="px-3 py-10 text-center text-sm text-chrome-400">
                            {{ __('No bookings here yet.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-3">{{ $bookings->links('vendor.pagination.compact') }}</div>

    {{-- The booking in full. --}}
    @if ($viewing !== null)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-chrome-900/40 p-4"
             x-on:keydown.escape.window="$wire.closeView()">
            <div class="flex min-h-full items-start justify-center py-8">
                <div class="w-full max-w-2xl rounded-2xl bg-white shadow-pop" x-on:click.outside="$wire.closeView()">
                    <div class="flex items-center justify-between border-b border-chrome-200 px-5 py-3">
                        <h2 class="text-base font-bold text-chrome-900">
                            {{ __('Web booking') }} — <span dir="ltr">{{ $viewing->source_reference }}</span>
                        </h2>
                        <button type="button" wire:click="closeView"
                                class="text-lg leading-none text-chrome-400 hover:text-chrome-700" aria-label="{{ __('Close') }}">&times;</button>
                    </div>

                    <div class="max-h-[70vh] overflow-y-auto px-5 py-4">
                        <dl class="grid gap-3 sm:grid-cols-2">
                            @foreach ([
                                __('Name') => $viewing->customerName(),
                                __('Phone') => $viewing->phone ?: '—',
                                __('Email') => $viewing->email ?: '—',
                                __('Address') => trim(($viewing->address ?? '') . ' ' . ($viewing->town ?? '')) ?: '—',
                                __('Product') => $viewing->product ?: '—',
                                __('Qty') => (string) $viewing->quantity,
                                __('Pick up') => $viewing->pickup_location ?: '—',
                                __('Drop off') => $viewing->dropoff_location ?: '—',
                                __('From date') => $viewing->pickup_at?->isoFormat('DD-MMM-YY HH:mm') ?? '—',
                                __('To date') => $viewing->dropoff_at?->isoFormat('DD-MMM-YY HH:mm') ?? '—',
                                __('Payment') => trim(($viewing->payment_mode ?? '—') . ' · ' . ($viewing->payment_status ?? '—')),
                                __('Total') => $viewing->total !== null ? \App\Erp\Views\ValueFormat::money($viewing->total) : '—',
                            ] as $label => $value)
                                <div>
                                    <dt class="text-xs font-medium uppercase tracking-wide text-chrome-400">{{ $label }}</dt>
                                    <dd class="text-sm text-chrome-800">{{ $value }}</dd>
                                </div>
                            @endforeach
                        </dl>

                        @if ($viewing->notes)
                            <div class="mt-4">
                                <p class="text-xs font-medium uppercase tracking-wide text-chrome-400">{{ __('Notes') }}</p>
                                <p class="mt-1 whitespace-pre-line text-sm text-chrome-600">{{ $viewing->notes }}</p>
                            </div>
                        @endif

                        @if ($viewing->rental_order_id !== null)
                            <p class="mt-4 rounded-xl bg-emerald-50 px-4 py-2 text-sm text-emerald-800 ring-1 ring-emerald-200">
                                {{ __('An order was raised from this booking.') }}
                                <a href="{{ url('/app/rental/order/' . $viewing->rental_order_id) }}" wire:navigate
                                   class="font-semibold underline">{{ __('Open the order') }}</a>
                            </p>
                        @endif

                        {{-- Everything the website sent, including anything this
                             screen has no column for. A booking is a customer's
                             own words about dates and money; if we have not
                             mapped a field yet, it should still be readable. --}}
                        <details class="mt-4 rounded-xl bg-chrome-50 p-3">
                            <summary class="cursor-pointer text-xs font-semibold uppercase tracking-wide text-chrome-500">
                                {{ __('Exactly what the website sent') }}
                            </summary>
                            <pre class="mt-2 overflow-x-auto whitespace-pre-wrap break-all text-[11px] text-chrome-600" dir="ltr">{{ json_encode($viewing->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                        </details>
                    </div>

                    <div class="flex flex-wrap items-center justify-between gap-2 border-t border-chrome-200 px-5 py-3">
                        <button type="button" wire:click="closeView" class="o-btn-ghost text-sm">{{ __('Close') }}</button>

                        @if ($mayAct)
                            <div class="flex flex-wrap items-center gap-2">
                                @if ($viewing->isPending())
                                    <button type="button" wire:click="markComplete"
                                            class="rounded-lg border border-chrome-200 px-3 py-1.5 text-sm font-medium text-chrome-700 transition hover:bg-chrome-50">
                                        {{ __('Mark complete') }}
                                    </button>
                                @else
                                    <button type="button" wire:click="markPending"
                                            class="rounded-lg border border-chrome-200 px-3 py-1.5 text-sm font-medium text-chrome-700 transition hover:bg-chrome-50">
                                        {{ __('Put back on pending') }}
                                    </button>
                                @endif

                                @if ($viewing->rental_order_id === null)
                                    <button type="button" wire:click="createOrder" wire:loading.attr="disabled"
                                            class="o-btn-primary text-sm">{{ __('Create rental order') }}</button>
                                @endif
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
