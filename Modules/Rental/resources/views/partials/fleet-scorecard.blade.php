{{--
    One car's scorecard: the numbers that say whether it is worth owning.

    "Underused" and "renting cheap" look identical in a revenue column and need
    opposite fixes - one is a demand problem, the other a pricing one - so the
    scorecard reports utilisation and achieved rate side by side rather than
    folding them into a single score.
--}}
@php
    $money = static fn (float $v): string => \App\Erp\Views\ValueFormat::money($v);
    $pace = $row['pace'];
@endphp

<div class="grid gap-4 lg:grid-cols-4">

    <div class="rounded-xl bg-white p-4 ring-1 ring-chrome-900/[0.06]">
        <div class="text-[11px] font-bold uppercase tracking-wide text-chrome-400">{{ __('How hard it worked') }}</div>
        <div class="mt-2 text-xl font-bold text-chrome-900">{{ $row['utilisation'] === null ? '—' : $row['utilisation'].'%' }}</div>
        <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-chrome-100">
            <div class="h-full rounded-full bg-sky-500" style="width: {{ min(100, (int) ($row['utilisation'] ?? 0)) }}%"></div>
        </div>
        <dl class="mt-3 space-y-1 text-xs">
            <div class="flex justify-between"><dt class="text-chrome-500">{{ __('On hire') }}</dt><dd class="font-semibold text-chrome-800">{{ __(':count days', ['count' => $row['rentedDays']]) }}</dd></div>
            <div class="flex justify-between"><dt class="text-chrome-500">{{ __('Stood still') }}</dt><dd class="font-semibold text-chrome-800">{{ __(':count days', ['count' => $row['idleDays']]) }}</dd></div>
        </dl>
    </div>

    <div class="rounded-xl bg-white p-4 ring-1 ring-chrome-900/[0.06]">
        <div class="text-[11px] font-bold uppercase tracking-wide text-chrome-400">{{ __('What it earned') }}</div>
        <div class="mt-2 text-xl font-bold text-chrome-900">{{ $money($row['total']) }}</div>
        <dl class="mt-3 space-y-1 text-xs">
            <div class="flex justify-between"><dt class="text-chrome-500">{{ __('Rent A Car') }}</dt><dd class="font-semibold text-chrome-800">{{ $money($row['earned']) }}</dd></div>
            @if ($report['hasLimo'])
                {{-- The limo desk books cars out of this same fleet, so a car's
                     whole contribution was invisible while the two apps
                     reported separately. --}}
                <div class="flex justify-between"><dt class="text-chrome-500">{{ __('Limousine') }}</dt><dd class="font-semibold text-chrome-800">{{ $money($row['limo']) }}</dd></div>
            @endif
            <div class="flex justify-between"><dt class="text-chrome-500">{{ __('Per day on hire') }}</dt><dd class="font-semibold text-chrome-800">{{ $money($row['perRentedDay']) }}</dd></div>
            <div class="flex justify-between"><dt class="text-chrome-500">{{ __('Per day owned') }}</dt><dd class="font-semibold text-chrome-800">{{ $money($row['perAvailableDay']) }}</dd></div>
        </dl>
    </div>

    <div class="rounded-xl bg-white p-4 ring-1 ring-chrome-900/[0.06]">
        <div class="text-[11px] font-bold uppercase tracking-wide text-chrome-400">{{ __('What it cost') }}</div>
        <div class="mt-2 text-xl font-bold {{ $row['net'] < 0 ? 'text-red-600' : 'text-chrome-900' }}">{{ $money($row['net']) }}</div>
        <dl class="mt-3 space-y-1 text-xs">
            <div class="flex justify-between"><dt class="text-chrome-500">{{ __('Service and repairs') }}</dt><dd class="font-semibold text-chrome-800">{{ $money($row['maintenance']) }}</dd></div>
            <div class="flex justify-between">
                <dt class="text-chrome-500">{{ __('Idle days cost') }}</dt>
                <dd class="font-semibold text-amber-700">{{ $money($row['idleCost']) }}</dd>
            </div>
        </dl>
        <p class="mt-2 text-[11px] leading-snug text-chrome-400">
            {{ __('Idle cost is what the standing days would have earned at this car\'s own rate.') }}
        </p>
    </div>

    <div class="rounded-xl bg-white p-4 ring-1 ring-chrome-900/[0.06]">
        <div class="text-[11px] font-bold uppercase tracking-wide text-chrome-400">{{ __('Against its target') }}</div>
        @if ($row['yearlyTarget'] > 0)
            <div class="mt-2 text-xl font-bold {{ $pace !== null && $pace >= 100 ? 'text-emerald-600' : 'text-amber-600' }}">
                {{ $pace === null ? '—' : $pace.'%' }}
                <span class="text-xs font-medium text-chrome-400">{{ __('of pace') }}</span>
            </div>
            <dl class="mt-3 space-y-1 text-xs">
                <div class="flex justify-between"><dt class="text-chrome-500">{{ __('Monthly') }}</dt><dd class="font-semibold text-chrome-800">{{ $money($row['monthlyTarget']) }}</dd></div>
                <div class="flex justify-between"><dt class="text-chrome-500">{{ __('Yearly') }}</dt><dd class="font-semibold text-chrome-800">{{ $money($row['yearlyTarget']) }}</dd></div>
                <div class="flex justify-between"><dt class="text-chrome-500">{{ __('Year so far') }}</dt><dd class="font-semibold text-chrome-800">{{ $row['attainment'] === null ? '—' : $row['attainment'].'%' }}</dd></div>
            </dl>
            @if ($row['yearlyDerived'])
                {{-- Say so rather than passing 12 x monthly off as a plan. --}}
                <p class="mt-2 text-[11px] leading-snug text-chrome-400">
                    {{ __('No yearly target set, so this is 12 × the monthly one. Set a real one on the car page.') }}
                </p>
            @endif
        @else
            <p class="mt-2 text-sm text-chrome-400">{{ __('No target set for this car.') }}</p>
        @endif
        @if ($row['id'] !== null)
            <a href="{{ url('/app/rental/vehicle/'.$row['id']) }}" wire:navigate
                class="mt-3 inline-block text-xs font-semibold text-primary-700 hover:underline">{{ __('Open the car') }}</a>
        @endif
    </div>
</div>
