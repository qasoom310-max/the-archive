{{--
    Deposit refunds: every deposit still held, soonest to go back first.
    Three groups — due now, still inside the hold, car not back yet. A row opens
    its order, which is where a deposit is settled.
--}}
@php
    $money = static fn (float $v): string => \App\Erp\Views\ValueFormat::money($v);
    $meta = [
        'due' => [
            'title' => __('Due now'),
            'hint' => __('The hold has ended. These can be refunded today.'),
            'empty' => __('No deposit is due right now.'),
            'box' => 'border-emerald-200 bg-emerald-50/70',
            'num' => 'text-emerald-700',
        ],
        'coming' => [
            'title' => __('Coming up'),
            'hint' => __('The car is back. The deposit is held for :days days after the return.', ['days' => $holdDays]),
            'empty' => __('No deposit is inside its hold.'),
            'box' => 'border-amber-200 bg-amber-50/70',
            'num' => 'text-amber-700',
        ],
        'out' => [
            'title' => __('Car still out'),
            'hint' => __('The car is not back yet. The date is a guess from the day it is due back.'),
            'empty' => __('No car that is out has a deposit.'),
            'box' => 'border-sky-200 bg-sky-50/70',
            'num' => 'text-sky-700',
        ],
    ];
@endphp

<div class="mx-auto max-w-5xl px-4 py-6 sm:px-6" data-deposit-refunds>
    <div class="mb-5">
        <h1 class="text-2xl font-bold tracking-tight text-chrome-900">{{ __('Deposit refunds') }}</h1>
        <p class="mt-1 text-sm text-chrome-500">{{ __('Every deposit we still hold, and when it has to go back.') }}</p>
    </div>

    {{-- The three totals; each jumps to its list. --}}
    <div class="mb-6 grid grid-cols-1 gap-3 sm:grid-cols-3">
        @foreach ($meta as $key => $m)
            <a href="#refunds-{{ $key }}" data-refund-box="{{ $key }}"
               class="block rounded-2xl border px-4 py-3 transition hover:-translate-y-0.5 hover:shadow-sm {{ $m['box'] }}">
                <div class="text-xs font-semibold uppercase tracking-wide text-chrome-600">{{ $m['title'] }}</div>
                <div class="mt-1 flex items-baseline justify-between gap-2">
                    <span class="text-2xl font-bold {{ $m['num'] }}">{{ count($groups[$key]['rows']) }}</span>
                    <span class="text-sm font-semibold text-chrome-700">{{ $money($groups[$key]['total']) }}</span>
                </div>
            </a>
        @endforeach
    </div>

    @unless ($canRefundEarly)
        <p class="mb-4 rounded-lg bg-chrome-50 px-3 py-2 text-xs text-chrome-600">{{ __('Only a super-admin can refund a deposit before its hold ends.') }}</p>
    @endunless

    @foreach ($meta as $key => $m)
        <section id="refunds-{{ $key }}" data-refund-group="{{ $key }}" class="mb-6 scroll-mt-24 overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-chrome-900/[0.06]">
            <div class="border-b border-chrome-100 px-4 py-3">
                <div class="flex items-center gap-2">
                    <h2 class="text-sm font-semibold text-chrome-800">{{ $m['title'] }}</h2>
                    <span class="rounded-full bg-chrome-100 px-2 py-0.5 text-[11px] font-bold text-chrome-600">{{ count($groups[$key]['rows']) }}</span>
                    <span class="ms-auto text-sm font-bold text-chrome-800">{{ $money($groups[$key]['total']) }}</span>
                </div>
                <p class="mt-0.5 text-xs text-chrome-500">{{ $m['hint'] }}</p>
            </div>

            @if ($groups[$key]['rows'] === [])
                <p class="px-4 py-5 text-sm text-chrome-400">{{ $m['empty'] }}</p>
            @else
                <ul class="divide-y divide-chrome-100">
                    @foreach ($groups[$key]['rows'] as $row)
                        @php
                            $o = $row['order'];
                            $days = $row['days'];
                            $when = $row['date']?->format('Y-m-d') ?? '—';
                        @endphp
                        <li>
                            <a href="{{ url('/app/rental/order/' . $o->id) }}" wire:navigate data-refund-row="{{ $o->id }}"
                               class="flex flex-col gap-2 px-4 py-3 transition hover:bg-chrome-50 sm:flex-row sm:items-center sm:justify-between sm:gap-3">
                                <div class="min-w-0">
                                    <div class="truncate font-medium text-chrome-800">{{ $o->customer?->name ?? '—' }} <span class="text-chrome-400">· {{ $o->reference }}</span></div>
                                    <div class="truncate text-xs text-chrome-500">
                                        {{ $o->vehicle?->displayName() ?? '—' }} ·
                                        @if ($key === 'out')
                                            {{ __('due back') }} {{ $o->end_date?->format('Y-m-d') ?? '—' }}
                                        @else
                                            {{ __('returned') }} {{ $o->returned_at?->format('Y-m-d') }}
                                        @endif
                                    </div>
                                    @if ($row['fines'] || $row['charges'])
                                        <div class="mt-1 flex flex-wrap gap-1.5">
                                            @if ($row['fines'])
                                                <span class="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold text-amber-800">{{ __('Traffic fines not checked yet') }}</span>
                                            @endif
                                            @if ($row['charges'])
                                                <span class="rounded-full bg-red-100 px-2 py-0.5 text-[10px] font-semibold text-red-700">{{ __('Damage or extra charges on the return') }}</span>
                                            @endif
                                        </div>
                                    @endif
                                </div>
                                <div class="flex shrink-0 items-center justify-between gap-3 text-sm sm:justify-end">
                                    <div class="text-start sm:text-end">
                                        <div class="font-semibold text-chrome-800">{{ $money($o->deposit) }}</div>
                                        <div class="text-[11px] text-chrome-500">
                                            @if ($key === 'due')
                                                {{ __('since') }} {{ $when }}
                                            @elseif ($days !== null && $days > 0)
                                                {{ $key === 'out' ? __('about') : __('on') }} {{ $when }} · {{ __('in :count days', ['count' => $days]) }}
                                            @else
                                                {{ $key === 'out' ? __('about') : __('on') }} {{ $when }}
                                            @endif
                                        </div>
                                    </div>
                                    @if ($key === 'due')
                                        <span class="rounded-lg bg-emerald-600 px-2.5 py-1 text-xs font-semibold text-white">{{ __('Refund') }} →</span>
                                    @elseif ($canRefundEarly)
                                        <span class="rounded-lg bg-primary-400 px-2.5 py-1 text-xs font-semibold text-chrome-900">{{ __('Refund early') }} →</span>
                                    @else
                                        <span class="rounded-lg bg-chrome-100 px-2.5 py-1 text-xs font-semibold text-chrome-500">{{ __('Locked') }}</span>
                                    @endif
                                </div>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endforeach
</div>
