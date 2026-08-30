@php $money = fn ($v) => \App\Erp\Views\ValueFormat::money($v); @endphp

<div class="mx-auto w-full max-w-6xl p-4 sm:p-6">
    <x-page-header :title="__('Refund coupons')" :subtitle="__('Credit from cancelled trips, spendable on future bookings.')" icon="ticket" accent="indigo">
        <x-slot:actions>
            <a href="{{ url('/app/limousine/booking') }}" wire:navigate class="o-btn-ghost">{{ __('Bookings') }}</a>
        </x-slot:actions>
    </x-page-header>

    {{-- The one figure worth leading with: credit the business still owes. --}}
    <div class="mb-5 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/5 sm:p-5">
        <p class="text-[11px] font-semibold uppercase tracking-wide text-chrome-500">{{ __('Outstanding credit') }}</p>
        <p class="mt-1 text-2xl font-bold text-amber-600">{{ $money($outstanding) }}</p>
        <p class="mt-0.5 text-xs text-chrome-400">{{ __('Unspent, unexpired coupons — value customers can still put towards a trip.') }}</p>
    </div>

    @php
        $tabs = ['active' => __('Active'), 'used' => __('Used'), 'expired' => __('Expired'), 'all' => __('All')];
    @endphp
    <div class="mb-4 flex flex-wrap items-center gap-1 border-b border-chrome-200">
        @foreach ($tabs as $key => $label)
            <button wire:click="$set('tab', '{{ $key }}')"
                class="-mb-px flex items-center gap-1.5 border-b-2 px-4 py-2 text-sm font-medium {{ $tab === $key ? 'border-primary-600 text-primary-700' : 'border-transparent text-chrome-500 hover:text-chrome-800' }}">
                {{ $label }}
                <span class="rounded-full bg-chrome-100 px-1.5 text-[11px] text-chrome-500">{{ $counts[$key] ?? 0 }}</span>
            </button>
        @endforeach
    </div>

    <div class="mb-4">
        <input type="search" wire:model.live.debounce.300ms="search" class="o-input w-full max-w-md text-sm"
               placeholder="{{ __('Coupon code, customer, or cancelled trip reference…') }}">
    </div>

    <div class="overflow-x-auto rounded-2xl bg-white shadow-sm ring-1 ring-chrome-900/[0.06]">
        <table class="w-full divide-y divide-chrome-100 text-sm">
            <thead class="bg-chrome-50 text-xs font-semibold uppercase tracking-wide text-chrome-500">
                <tr>
                    <th class="px-3 py-2 text-start">{{ __('Code') }}</th>
                    <th class="px-3 py-2 text-start">{{ __('Customer') }}</th>
                    <th class="hidden px-3 py-2 text-start sm:table-cell">{{ __('From trip') }}</th>
                    <th class="px-3 py-2 text-end">{{ __('Issued') }}</th>
                    <th class="hidden px-3 py-2 text-end md:table-cell">{{ __('Used') }}</th>
                    <th class="px-3 py-2 text-end">{{ __('Remaining') }}</th>
                    <th class="hidden px-3 py-2 text-start lg:table-cell">{{ __('Expires') }}</th>
                    <th class="px-3 py-2 text-start">{{ __('Status') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-chrome-50">
                @forelse ($rows as $c)
                    @php
                        $badge = [
                            'active' => 'bg-emerald-100 text-emerald-700',
                            'used' => 'bg-chrome-200 text-chrome-700',
                            'expired' => 'bg-red-100 text-red-700',
                        ][$c->state()] ?? 'bg-chrome-100 text-chrome-600';
                    @endphp
                    <tr wire:key="cpn-{{ $c->id }}" class="hover:bg-chrome-50">
                        <td class="px-3 py-2 font-semibold text-chrome-900">{{ $c->code }}</td>
                        <td class="px-3 py-2 text-chrome-700">{{ $c->customer?->name ?? '—' }}</td>
                        <td class="hidden px-3 py-2 text-chrome-500 sm:table-cell">{{ $c->leg_reference ?: '—' }}</td>
                        <td class="px-3 py-2 text-end tabular-nums text-chrome-700">{{ $money($c->amount) }}</td>
                        <td class="hidden px-3 py-2 text-end tabular-nums text-chrome-500 md:table-cell">{{ $money($c->used()) }}</td>
                        <td class="px-3 py-2 text-end tabular-nums font-bold {{ $c->remaining() > 0 && ! $c->isExpired() ? 'text-emerald-700' : 'text-chrome-400' }}">
                            {{ $money($c->remaining()) }}
                        </td>
                        <td class="hidden px-3 py-2 text-chrome-500 lg:table-cell">{{ $c->expires_at?->isoFormat('DD-MMM-YY') ?? '—' }}</td>
                        <td class="px-3 py-2">
                            <span class="rounded px-2 py-0.5 text-[11px] font-semibold uppercase {{ $badge }}">{{ __(ucfirst($c->state())) }}</span>
                        </td>
                    </tr>
                    {{-- Where the credit went, so a part-spent coupon can be
                         explained to the customer who asks. --}}
                    @if ($c->redemptions->isNotEmpty())
                        <tr wire:key="cpn-r-{{ $c->id }}" class="bg-chrome-50/60">
                            <td colspan="8" class="px-3 pb-2 pt-0 text-[11px] text-chrome-500">
                                @foreach ($c->redemptions as $r)
                                    <span class="me-3 inline-block">
                                        −{{ $money($r->amount) }}
                                        @if ($r->booking_reference) · {{ $r->booking_reference }} @endif
                                        · {{ $r->created_at?->isoFormat('DD-MMM-YY') }}
                                    </span>
                                @endforeach
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr><td colspan="8" class="px-4 py-10 text-center text-sm text-chrome-400">{{ __('No coupons here.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $coupons->links() }}</div>
</div>
