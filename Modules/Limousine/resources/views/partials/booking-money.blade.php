{{-- Everything that makes up this booking's total and everything paid
     against it (Modules\Limousine\Support\BookingMoney). --}}
@php
    $m = static fn (float $v): string => \App\Erp\Views\ValueFormat::money($v);
    $tripStatus = [
        'queue' => __('Queue'), 'confirmed' => __('Confirmed'), 'active' => __('Active'),
        'completed' => __('Completed'), 'cancelled' => __('Cancelled'),
    ];
    $outcome = [
        'refunded' => __('refunded'), 'coupon' => __('kept, coupon given'),
        'forfeited' => __('kept, nothing given back'), 'none' => __('nothing was paid'),
    ];
    $methods = collect(\Modules\Limousine\Models\LimoReceipt::methodOptions())->pluck('label', 'value')->all();
@endphp

<div class="mt-5 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/[0.06] sm:p-6" data-booking-money>
    <h2 class="text-base font-semibold text-chrome-800">{{ __('Money on this booking') }}</h2>
    <p class="text-xs text-chrome-400">{{ __('What the total is made of, and every payment recorded against it.') }}</p>

    @if ($money['disagrees'])
        <div class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800" data-booking-money-disagrees>
            {{ __('The payments on file add up to more than "Received". Part of the money was recorded in one place only, so the balance shown in the list is too high. Check the payments below.') }}
        </div>
    @endif
    @if ($money['links_waiting'] > 0)
        <div class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800" data-booking-money-link-waiting>
            {{ __('A payment link for :amount has not been paid yet. If the customer says they paid it online, the website did not tell us: check the store order.', ['amount' => $m($money['links_waiting'])]) }}
        </div>
    @endif

    <div class="mt-4 grid gap-6 lg:grid-cols-2">
        <div>
            <h3 class="text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('The total') }}</h3>
            <dl class="mt-2 space-y-1.5 text-sm">
                @foreach ($money['trips'] as $trip)
                    <div class="flex justify-between gap-3 {{ $trip['billed'] ? '' : 'text-chrome-400' }}" data-money-trip>
                        <dt>
                            {{ __('Trip :ref', ['ref' => $trip['reference']]) }}
                            <span class="text-chrome-400">· {{ $tripStatus[$trip['status']] ?? $trip['status'] }}@if ($trip['outcome'] !== null), {{ $outcome[$trip['outcome']] ?? $trip['outcome'] }}@endif</span>
                        </dt>
                        <dd class="shrink-0 {{ $trip['billed'] ? 'text-chrome-700' : 'line-through' }}">{{ $m($trip['amount']) }}</dd>
                    </div>
                @endforeach
                @if ($money['meet_greet'] > 0)
                    <div class="flex justify-between"><dt>{{ __('Meet & greet') }}</dt><dd class="text-chrome-700">{{ $m($money['meet_greet']) }}</dd></div>
                @endif
                <div class="flex justify-between border-t border-chrome-100 pt-1.5 font-semibold text-chrome-800"><dt>{{ __('Total') }}</dt><dd>{{ $m($money['total']) }}</dd></div>
            </dl>
            @if ($money['coupons_issued'] !== [])
                <p class="mt-3 text-xs text-chrome-500">
                    {{ __('Coupons given from this booking:') }}
                    @foreach ($money['coupons_issued'] as $c){{ $loop->first ? '' : ', ' }}{{ $c['code'] }} ({{ $m($c['amount']) }})@endforeach
                </p>
            @endif
        </div>

        <div>
            <h3 class="text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Payments') }}</h3>
            <dl class="mt-2 space-y-1.5 text-sm">
                @forelse ($money['receipts'] as $r)
                    <div class="flex justify-between gap-3" data-money-receipt>
                        <dt>
                            {{ $r['reference'] }}
                            <span class="text-chrome-400">· {{ $r['date'] }} · {{ __($methods[$r['method']] ?? $r['method']) }}@if (! $r['through_booking']) · {{ __('on invoice :ref only', ['ref' => $r['invoice'] ?? '—']) }}@endif</span>
                        </dt>
                        <dd class="shrink-0 text-chrome-700">{{ $m($r['amount']) }}</dd>
                    </div>
                @empty
                    <p class="text-chrome-400">{{ __('No receipts.') }}</p>
                @endforelse
                @foreach ($money['coupons_spent'] as $c)
                    <div class="flex justify-between gap-3"><dt>{{ __('Coupon :code spent', ['code' => $c['code']]) }}</dt><dd class="shrink-0 text-chrome-700">{{ $m($c['amount']) }}</dd></div>
                @endforeach
                @foreach ($money['links'] as $l)
                    <div class="flex justify-between gap-3 text-chrome-500" data-money-link>
                        <dt>
                            {{ __('Payment link') }}
                            <span class="text-chrome-400">· {{ $l['created'] }} ·
                                @if ($l['status'] === 'paid') {{ __('paid :when', ['when' => $l['paid']]) }}
                                @elseif ($l['status'] === 'cancelled') {{ __('cancelled') }}
                                @else <span class="font-semibold text-amber-700">{{ __('not paid yet') }}</span>
                                @endif
                            </span>
                        </dt>
                        <dd class="shrink-0">{{ $m($l['amount']) }}</dd>
                    </div>
                @endforeach
            </dl>

            <dl class="mt-3 space-y-1 border-t border-chrome-100 pt-2 text-sm">
                <div class="flex justify-between text-chrome-500"><dt>{{ __('Taken through the booking (advance)') }}</dt><dd>{{ $m($money['advance']) }}</dd></div>
                <div class="flex justify-between text-chrome-500"><dt>{{ __('Paid on the invoice') }}</dt><dd>{{ $m($money['invoices_paid']) }}</dd></div>
                <div class="flex justify-between font-semibold text-chrome-800"><dt>{{ __('Received') }}</dt><dd>{{ $m($money['received']) }}</dd></div>
                <div class="flex justify-between font-semibold {{ $money['owed'] > 0 ? 'text-amber-700' : 'text-emerald-700' }}"><dt>{{ __('Still owed') }}</dt><dd>{{ $m($money['owed']) }}</dd></div>
            </dl>
        </div>
    </div>
</div>
