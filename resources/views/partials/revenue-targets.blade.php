{{--
    The owner's money band: collected revenue plus this month's and this year's
    targets. SUPER-ADMIN ONLY - the host wraps this whole include in that check.

    A target box states a percentage of the business's whole income, so it gives
    the income away to anyone who can divide. Gating the revenue card while
    leaving the targets on screen would gate nothing.

    Expects: $tile (shared card classes), $revenue (float), $targets (the array
    from RevenueTargets::progress()), $gradient (the revenue card's fill),
    $unpaidHref (where chasing a balance starts), and $editingTargets /
    $targetMonthly / $targetYearly from EditsRevenueTargets.
--}}
@php
    $cashIcon = '<path d="M1 4.25C1 3.56 1.56 3 2.25 3h15.5c.69 0 1.25.56 1.25 1.25v8.5c0 .69-.56 1.25-1.25 1.25H2.25C1.56 14 1 13.44 1 12.75v-8.5ZM10 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM4.25 6.5a1 1 0 1 1-2 0 1 1 0 0 1 2 0ZM17.75 13.5a1 1 0 1 0-2 0 1 1 0 0 0 2 0Z"/><path d="M2.673 16.5a.75.75 0 0 1 .904-.552 24.6 24.6 0 0 0 12.846 0 .75.75 0 1 1 .352 1.458 26.1 26.1 0 0 1-13.55 0 .75.75 0 0 1-.552-.906Z"/>';

    $boxes = [
        [
            'key' => 'month',
            'title' => __('Monthly target'),
            'data' => $targets['month'],
            'schedule' => $schedules['month'] ?? null,
            'tint' => 'bg-sky-50 text-sky-600 ring-sky-100',
            'bar' => 'bg-sky-500',
            'icon' => '<path fill-rule="evenodd" d="M5.75 2a.75.75 0 0 1 .75.75V4h7V2.75a.75.75 0 0 1 1.5 0V4h.25A2.75 2.75 0 0 1 18 6.75v8.5A2.75 2.75 0 0 1 15.25 18H4.75A2.75 2.75 0 0 1 2 15.25v-8.5A2.75 2.75 0 0 1 4.75 4H5V2.75A.75.75 0 0 1 5.75 2Zm-1 5.5c-.69 0-1.25.56-1.25 1.25v6.5c0 .69.56 1.25 1.25 1.25h10.5c.69 0 1.25-.56 1.25-1.25v-6.5c0-.69-.56-1.25-1.25-1.25H4.75Z" clip-rule="evenodd"/>',
        ],
        [
            'key' => 'year',
            'title' => __('Yearly target'),
            'data' => $targets['year'],
            'schedule' => $schedules['year'] ?? null,
            'tint' => 'bg-violet-50 text-violet-600 ring-violet-100',
            'bar' => 'bg-violet-500',
            'icon' => '<path fill-rule="evenodd" d="M10 1a6 6 0 0 0-3.815 10.631A4.5 4.5 0 0 0 5.5 15v2.25a.75.75 0 0 0 1.1.664L10 16.15l3.4 1.764a.75.75 0 0 0 1.1-.664V15a4.5 4.5 0 0 0-.685-3.369A6 6 0 0 0 10 1Zm0 1.5a4.5 4.5 0 1 1 0 9 4.5 4.5 0 0 1 0-9Z" clip-rule="evenodd"/>',
        ],
    ];
@endphp

<div class="mb-3 flex items-center gap-2">
    <h2 class="text-xs font-bold uppercase tracking-wider text-chrome-500">{{ __('Money') }}</h2>
    <span class="rounded-full bg-chrome-100 px-2 py-0.5 text-[11px] font-medium text-chrome-500">{{ __('Owner only') }}</span>
    <span class="h-px flex-1 bg-chrome-200"></span>
    @isset($fleetHref)
        {{-- The car-by-car scorecard: the same money, broken down far enough
             to say whether each car is worth owning. --}}
        <a href="{{ $fleetHref }}" wire:navigate
            class="flex items-center gap-1.5 rounded-lg px-2 py-1 text-[11px] font-semibold text-chrome-500 transition hover:bg-chrome-100 hover:text-chrome-800">
            <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor"><path d="M3 3.5A1.5 1.5 0 0 1 4.5 2h11A1.5 1.5 0 0 1 17 3.5v13a1.5 1.5 0 0 1-1.5 1.5h-11A1.5 1.5 0 0 1 3 16.5v-13ZM6 6a.75.75 0 0 0 0 1.5h8A.75.75 0 0 0 14 6H6Zm0 3.5a.75.75 0 0 0 0 1.5h8a.75.75 0 0 0 0-1.5H6ZM6 13a.75.75 0 0 0 0 1.5h4A.75.75 0 0 0 10 13H6Z"/></svg>
            {{ $fleetLabel ?? __('Fleet earnings') }}
        </a>
    @endisset
    <button type="button" wire:click="openTargets"
        class="flex items-center gap-1.5 rounded-lg px-2 py-1 text-[11px] font-semibold text-chrome-500 transition hover:bg-chrome-100 hover:text-chrome-800">
        <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor"><path d="M2.695 14.763l-1.262 3.154a.5.5 0 0 0 .65.65l3.155-1.262a4 4 0 0 0 1.343-.885L17.5 5.5a2.121 2.121 0 0 0-3-3L3.58 13.42a4 4 0 0 0-.885 1.343Z"/></svg>
        {{ __('Edit targets') }}
    </button>
</div>

@if (session()->has('targets-saved'))
    <div class="mb-4 rounded-xl bg-emerald-50 px-4 py-2.5 text-sm font-medium text-emerald-700 ring-1 ring-emerald-100">
        {{ session('targets-saved') }}
    </div>
@endif

<div class="mb-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
    {{-- Collected revenue: the whole income, which is why this band is gated. --}}
    <div class="{{ $tile }} flex flex-col {{ $gradient }} ring-0">
        <div class="flex items-center justify-between">
            <span class="flex size-9 items-center justify-center rounded-xl bg-white/15 text-white ring-1 ring-white/20">
                <svg class="size-5" viewBox="0 0 20 20" fill="currentColor">{!! $cashIcon !!}</svg>
            </span>
            <span class="rounded-full bg-white/15 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-white/90">{{ __('Collected') }}</span>
        </div>
        <div class="mt-3 text-2xl font-bold tracking-tight text-white">{{ \App\Erp\Views\ValueFormat::money($revenue) }}</div>
        <div class="text-sm font-medium text-white/70">{{ __('Revenue') }}</div>
        {{-- What is still owed, all time: it says how much of the business's
             earnings are sitting with customers rather than in the bank. --}}
        <a href="{{ $unpaidHref }}" wire:navigate
            class="mt-auto flex items-center justify-between rounded-lg bg-black/15 px-3 py-2 transition hover:bg-black/25">
            <span class="flex items-center gap-1.5 text-xs font-medium text-white/80">
                <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M18 10A8 8 0 1 1 2 10a8 8 0 0 1 16 0Zm-7-4a1 1 0 1 1-2 0 1 1 0 0 1 2 0ZM9 9a1 1 0 0 0 0 2v3a1 1 0 0 0 1 1h1a1 1 0 1 0 0-2v-3a1 1 0 0 0-1-1H9Z" clip-rule="evenodd"/></svg>
                {{ __('Unpaid') }}
            </span>
            <span class="text-sm font-bold text-white">{{ \App\Erp\Views\ValueFormat::money($targets['outstanding']) }}</span>
        </a>
    </div>

    @foreach ($boxes as $box)
        @php
            $data = $box['data'];
            $pct = $data['pct'];
            // The bar stops at full even when the target is beaten - the number
            // above it already says 140%, and a bar past its own track is noise.
            $width = $pct === null ? 0 : max(0, min(100, $pct));
        @endphp
        <div class="{{ $tile }} flex flex-col">
            <div class="flex items-center justify-between">
                <span class="flex size-9 items-center justify-center rounded-xl ring-1 {{ $box['tint'] }}">
                    <svg class="size-5" viewBox="0 0 20 20" fill="currentColor">{!! $box['icon'] !!}</svg>
                </span>
                <span class="rounded-full bg-chrome-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-chrome-500">{{ $data['label'] }}</span>
            </div>

            <div class="mt-3 text-2xl font-bold tracking-tight text-chrome-900">
                {{ \App\Erp\Views\ValueFormat::money($data['earned']) }}
            </div>

            @if ($data['target'] === null)
                <div class="text-sm font-medium text-chrome-500">{{ $box['title'] }}</div>
                @include('partials.revenue-targets-unpaid', ['unpaid' => $data['unpaid'], 'href' => $unpaidHref])
                <button type="button" wire:click="openTargets"
                    class="mt-3 flex items-center justify-center rounded-lg bg-chrome-100 px-3 py-2 text-xs font-semibold text-chrome-600 transition hover:bg-chrome-200">
                    {{ __('Set a target') }}
                </button>
            @else
                <div class="text-sm font-medium text-chrome-500">
                    {{ $box['title'] }} · {{ \App\Erp\Views\ValueFormat::money($data['target']) }}
                </div>
                <div class="mt-3 h-2 w-full overflow-hidden rounded-full bg-chrome-100">
                    <div class="h-full rounded-full {{ $box['bar'] }}" style="width: {{ $width }}%"></div>
                </div>
                <div class="mt-2 flex items-center justify-between text-xs font-medium">
                    <span class="text-chrome-500">{{ __(':percent% of target', ['percent' => $pct]) }}</span>
                    @if ($data['remaining'] > 0)
                        <span class="text-chrome-500">{{ __(':amount to go', ['amount' => \App\Erp\Views\ValueFormat::money($data['remaining'])]) }}</span>
                    @else
                        <span class="font-semibold text-emerald-600">{{ __('Target met') }}</span>
                    @endif
                </div>
                @include('partials.revenue-targets-unpaid', ['unpaid' => $data['unpaid'], 'href' => $unpaidHref])
                @include('partials.revenue-target-source', ['source' => $data['source'], 'fleet' => $targets['fleet'] ?? null])
                @if ($box['key'] === 'year' && $targets['pace'] !== null)
                    {{-- Attainment against the part of the year already gone, so a
                         year that is on schedule reads 100 in March as in December. --}}
                    <div class="mt-2 text-xs font-medium {{ $targets['pace'] >= 100 ? 'text-emerald-600' : 'text-amber-600' }}">
                        {{ $targets['pace'] >= 100
                            ? __('Ahead of pace (:percent%)', ['percent' => $targets['pace']])
                            : __('Behind pace (:percent%)', ['percent' => $targets['pace']]) }}
                    </div>
                @endif
            @endif

            @include('partials.revenue-schedule', [
                'schedule' => $box['schedule'],
                'key' => $box['key'],
                'period' => $data['label'],
            ])
        </div>
    @endforeach
</div>

{{-- Edit targets --}}
@if ($editingTargets)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-chrome-900/50 p-4"
        x-on:keydown.escape.window="$wire.closeTargets()">
        <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl" x-on:click.outside="$wire.closeTargets()">
            <h3 class="text-lg font-bold text-chrome-900">{{ __('Revenue targets') }}</h3>
            <p class="mt-1 text-sm text-chrome-500">
                {{ __('The yearly target is set on its own, not twelve times the monthly one, because trade is seasonal. Leave a box empty for no target.') }}
            </p>

            <div class="mt-5 space-y-4">
                <div>
                    <label for="target-monthly" class="block text-sm font-medium text-chrome-700">{{ __('Monthly target') }}</label>
                    <input id="target-monthly" type="number" step="any" min="0" inputmode="decimal"
                        wire:model="targetMonthly" placeholder="0"
                        class="o-input mt-1 w-full" />
                    @error('targetMonthly')<p class="mt-1 text-xs font-medium text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="target-yearly" class="block text-sm font-medium text-chrome-700">{{ __('Yearly target') }}</label>
                    <input id="target-yearly" type="number" step="any" min="0" inputmode="decimal"
                        wire:model="targetYearly" placeholder="0"
                        class="o-input mt-1 w-full" />
                    @error('targetYearly')<p class="mt-1 text-xs font-medium text-red-600">{{ $message }}</p>@enderror
                </div>
            </div>

            <div class="mt-6 flex justify-end gap-2">
                <button type="button" wire:click="closeTargets"
                    class="rounded-lg px-4 py-2 text-sm font-semibold text-chrome-600 transition hover:bg-chrome-100">
                    {{ __('Cancel') }}
                </button>
                <button type="button" wire:click="saveTargets" wire:loading.attr="disabled"
                    class="o-btn-primary rounded-lg px-4 py-2 text-sm font-semibold">
                    {{ __('Save targets') }}
                </button>
            </div>
        </div>
    </div>
@endif
