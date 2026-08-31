<div class="mx-auto max-w-7xl p-4 sm:p-6">
    @php
        $ic = [
            'car' => '<path d="M3 9.5 4.2 6.6A2 2 0 0 1 6 5.5h8a2 2 0 0 1 1.8 1.1L17 9.5a2 2 0 0 1 1 1.7V13a1 1 0 0 1-1 1h-1a2 2 0 1 1-4 0H8a2 2 0 1 1-4 0H3a1 1 0 0 1-1-1v-1.8a2 2 0 0 1 1-1.7Z"/><circle cx="6.5" cy="14" r="1.5"/><circle cx="13.5" cy="14" r="1.5"/>',
            'queue' => '<path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm.75-13a.75.75 0 0 0-1.5 0v5c0 .414.336.75.75.75h4a.75.75 0 0 0 0-1.5h-3.25V5Z" clip-rule="evenodd"/>',
            'bolt' => '<path d="M11.983 1.907a.75.75 0 0 0-1.292-.657l-8.5 9.5A.75.75 0 0 0 2.75 12h6.572l-1.305 6.093a.75.75 0 0 0 1.292.657l8.5-9.5A.75.75 0 0 0 17.25 8h-6.572l1.305-6.093Z"/>',
            'check' => '<path fill-rule="evenodd" d="M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0Zm-3.97-3.03a.75.75 0 0 0-1.08.022L9.477 12.4l-1.92-1.92a.75.75 0 1 0-1.06 1.06l2.5 2.5a.75.75 0 0 0 1.08-.022l3.992-4.99a.75.75 0 0 0-.02-1.06Z" clip-rule="evenodd"/>',
            'alert' => '<path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.515 2.625H3.72c-1.345 0-2.188-1.458-1.515-2.625L8.485 2.495ZM10 5a.75.75 0 0 1 .75.75v3.5a.75.75 0 0 1-1.5 0v-3.5A.75.75 0 0 1 10 5Zm0 9a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" clip-rule="evenodd"/>',
            'cash' => '<path d="M1 4.25C1 3.56 1.56 3 2.25 3h15.5c.69 0 1.25.56 1.25 1.25v8.5c0 .69-.56 1.25-1.25 1.25H2.25C1.56 14 1 13.44 1 12.75v-8.5ZM10 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM4.25 6.5a1 1 0 1 1-2 0 1 1 0 0 1 2 0ZM17.75 13.5a1 1 0 1 0-2 0 1 1 0 0 0 2 0Z"/><path d="M2.673 16.5a.75.75 0 0 1 .904-.552 24.6 24.6 0 0 0 12.846 0 .75.75 0 1 1 .352 1.458 26.1 26.1 0 0 1-13.55 0 .75.75 0 0 1-.552-.906Z"/>',
            'calendar' => '<path fill-rule="evenodd" d="M5.75 2a.75.75 0 0 1 .75.75V4h7V2.75a.75.75 0 0 1 1.5 0V4h.25A2.75 2.75 0 0 1 18 6.75v8.5A2.75 2.75 0 0 1 15.25 18H4.75A2.75 2.75 0 0 1 2 15.25v-8.5A2.75 2.75 0 0 1 4.75 4H5V2.75A.75.75 0 0 1 5.75 2ZM3.5 8.5v6.75c0 .69.56 1.25 1.25 1.25h10.5c.69 0 1.25-.56 1.25-1.25V8.5h-13Z" clip-rule="evenodd"/>',
            'plus' => '<path d="M10 5a1 1 0 0 1 1 1v3h3a1 1 0 1 1 0 2h-3v3a1 1 0 1 1-2 0v-3H6a1 1 0 1 1 0-2h3V6a1 1 0 0 1 1-1Z"/>',
        ];
        $tile = 'rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/[0.06] transition hover:-translate-y-0.5 hover:shadow-md';
    @endphp

    {{-- ───────── Hero ───────── --}}
    <div class="relative mb-6 overflow-hidden rounded-3xl bg-gradient-to-br from-slate-950 via-indigo-950 to-slate-900 p-6 shadow-pop sm:p-8">
        <div class="pointer-events-none absolute -right-10 -top-16 size-72 rounded-full bg-indigo-500/25 blur-3xl"></div>
        <div class="pointer-events-none absolute -left-16 bottom-0 size-60 rounded-full bg-violet-500/10 blur-3xl"></div>
        <svg class="pointer-events-none absolute -bottom-8 right-6 size-56 text-white/[0.04]" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">{!! $ic['car'] !!}</svg>

        <div class="relative flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <span class="inline-flex items-center gap-1.5 rounded-full bg-indigo-400/15 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wider text-indigo-300 ring-1 ring-indigo-400/20">
                    <span class="size-1.5 rounded-full bg-indigo-400"></span>{{ __('Chauffeur service') }}
                </span>
                <h1 class="mt-3 text-2xl font-bold tracking-tight text-white sm:text-3xl">{{ __('Limousine') }}</h1>
                <p class="mt-1 text-sm text-white/55">{{ __('Bookings, trips & revenue at a glance.') }}</p>

                <div class="mt-5 grid max-w-md grid-cols-3 gap-3">
                    @php
                        $glance = [
                            ['label' => __('In queue'), 'value' => $queue, 'dot' => 'bg-amber-400'],
                            ['label' => __('Active'), 'value' => $active, 'dot' => 'bg-indigo-400'],
                            ['label' => __('Today'), 'value' => $todayCount, 'dot' => 'bg-emerald-400'],
                        ];
                    @endphp
                    @foreach ($glance as $g)
                        <div class="rounded-2xl bg-white/[0.06] px-3 py-2.5 ring-1 ring-white/10 backdrop-blur">
                            <div class="flex items-center gap-1.5 text-[11px] font-medium uppercase tracking-wide text-white/50">
                                <span class="size-1.5 rounded-full {{ $g['dot'] }}"></span>{{ $g['label'] }}
                            </div>
                            <div class="mt-1 text-2xl font-bold text-white">{{ $g['value'] }}</div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="flex flex-col items-stretch gap-3 lg:items-end">
                <a href="{{ url('/app/limousine/booking/new') }}" wire:navigate class="inline-flex items-center justify-center gap-1.5 rounded-md bg-indigo-500 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-indigo-500/30 transition hover:bg-indigo-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-300">
                    <svg class="size-4" viewBox="0 0 20 20" fill="currentColor">{!! $ic['plus'] !!}</svg>{{ __('New booking') }}
                </a>
                <div class="flex flex-wrap gap-2 lg:justify-end">
                    @foreach ([['Bookings', '/app/limousine/booking'], ['Quotations', '/app/limousine/quotation'], ['Invoices', '/app/limousine/invoice'], ['Receipts', '/app/limousine/receipt'], ['Coupons', '/app/limousine/coupon'], ['Expenses', '/app/limousine/expense'], ['Reports', '/app/limousine/reports']] as [$lbl, $href])
                        <a href="{{ url($href) }}" wire:navigate class="rounded-lg bg-white/10 px-3 py-1.5 text-xs font-medium text-white/90 ring-1 ring-white/10 transition hover:bg-white/20">{{ __($lbl) }}</a>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    {{-- ───────── Booking KPIs ───────── --}}
    <div class="mb-3 flex items-center gap-2">
        <h2 class="text-xs font-bold uppercase tracking-wider text-chrome-500">{{ __('Bookings') }}</h2>
        <span class="h-px flex-1 bg-chrome-200"></span>
    </div>
    <div class="mb-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
        @php
            $cards = [
                ['label' => __('Bookings Queue'), 'value' => $queue, 'href' => url('/app/limousine/booking?tab=queue'), 'icon' => 'queue', 'tint' => 'bg-amber-50 text-amber-600 ring-amber-100'],
                // Confirmed sits between Queue and Active and had no card, so a
                // trip that had been confirmed was counted on none of them: agreed
                // with the customer, and invisible everywhere but the queue's own tab.
                ['label' => __('Confirmed Trips'), 'value' => $confirmed, 'href' => url('/app/limousine/booking?tab=confirmed'), 'icon' => 'calendar', 'tint' => 'bg-sky-50 text-sky-600 ring-sky-100'],
                ['label' => __('Active Bookings'), 'value' => $active, 'href' => url('/app/limousine/booking?tab=active'), 'icon' => 'bolt', 'tint' => 'bg-indigo-50 text-indigo-600 ring-indigo-100'],
                ['label' => __('Completed Trips'), 'value' => $completed, 'href' => url('/app/limousine/booking?tab=completed'), 'icon' => 'check', 'tint' => 'bg-emerald-50 text-emerald-600 ring-emerald-100'],
                ['label' => __('Unpaid Bookings'), 'value' => $unpaid, 'href' => url('/app/limousine/booking'), 'icon' => 'alert', 'tint' => 'bg-red-50 text-red-600 ring-red-100'],
            ];
        @endphp
        @foreach ($cards as $card)
            <a href="{{ $card['href'] }}" wire:navigate class="{{ $tile }} hover:ring-indigo-300">
                <div class="flex items-center justify-between">
                    <span class="flex size-9 items-center justify-center rounded-xl ring-1 {{ $card['tint'] }}">
                        <svg class="size-5" viewBox="0 0 20 20" fill="currentColor">{!! $ic[$card['icon']] !!}</svg>
                    </span>
                    <svg class="size-4 text-chrome-300 rtl:rotate-180" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.168 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd"/></svg>
                </div>
                <div class="mt-3 text-3xl font-bold tracking-tight text-chrome-900">{{ $card['value'] }}</div>
                <div class="text-sm font-medium text-chrome-500">{{ $card['label'] }}</div>
            </a>
        @endforeach
        {{-- Revenue --}}
        <div class="{{ $tile }} bg-gradient-to-br from-indigo-600 to-violet-700 ring-0">
            <div class="flex items-center justify-between">
                <span class="flex size-9 items-center justify-center rounded-xl bg-white/15 text-white ring-1 ring-white/20">
                    <svg class="size-5" viewBox="0 0 20 20" fill="currentColor">{!! $ic['cash'] !!}</svg>
                </span>
                <span class="rounded-full bg-white/15 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-white/90">{{ __('Collected') }}</span>
            </div>
            <div class="mt-3 text-2xl font-bold tracking-tight text-white">{{ \App\Erp\Views\ValueFormat::money($revenue) }}</div>
            <div class="text-sm font-medium text-white/70">{{ __('Revenue') }}</div>
        </div>
    </div>

    {{-- ───────── Schedule strip ───────── --}}
    <div class="mb-3 flex items-center gap-2">
        <h2 class="text-xs font-bold uppercase tracking-wider text-chrome-500">{{ __('Schedule') }}</h2>
        <span class="h-px flex-1 bg-chrome-200"></span>
    </div>
    <div class="mb-8 grid gap-4 sm:grid-cols-3">
        @php
            // Each card opens the queue filtered to its own day, so the number
            // is a way in rather than a fact to go and look up by hand.
            $dayCards = [
                ['label' => __("Yesterday's Bookings"), 'value' => $yesterdayCount, 'badge' => __('Yesterday'), 'active' => false, 'date' => $yesterdayDate],
                ['label' => __("Today's Bookings"), 'value' => $todayCount, 'badge' => __('Today'), 'active' => true, 'date' => $todayDate],
                ['label' => __("Tomorrow's Bookings"), 'value' => $tomorrowCount, 'badge' => __('Tomorrow'), 'active' => false, 'date' => $tomorrowDate],
            ];
        @endphp
        @foreach ($dayCards as $card)
            <a href="{{ url('/app/limousine/booking') }}?from={{ $card['date'] }}&to={{ $card['date'] }}" wire:navigate
               class="relative block overflow-hidden rounded-2xl p-5 shadow-sm ring-1 transition hover:-translate-y-0.5 hover:shadow-pop {{ $card['active'] ? 'bg-gradient-to-br from-chrome-900 to-chrome-800 ring-0' : 'bg-white ring-chrome-900/[0.06]' }}">
                @if ($card['active'])<div class="pointer-events-none absolute -right-8 -top-10 size-32 rounded-full bg-indigo-500/25 blur-2xl"></div>@endif
                <div class="relative flex items-center justify-between">
                    <span class="flex size-9 items-center justify-center rounded-xl ring-1 {{ $card['active'] ? 'bg-white/10 text-indigo-300 ring-white/15' : 'bg-chrome-100 text-chrome-500 ring-chrome-200' }}">
                        <svg class="size-5" viewBox="0 0 20 20" fill="currentColor">{!! $ic['calendar'] !!}</svg>
                    </span>
                    <span class="rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide {{ $card['active'] ? 'bg-indigo-500/20 text-indigo-200' : 'bg-chrome-100 text-chrome-600' }}">{{ $card['badge'] }}</span>
                </div>
                <div class="relative mt-3 text-3xl font-bold tracking-tight {{ $card['active'] ? 'text-white' : 'text-chrome-900' }}">{{ $card['value'] }}</div>
                <div class="relative text-sm font-medium {{ $card['active'] ? 'text-white/60' : 'text-chrome-500' }}">{{ $card['label'] }}</div>
            </a>
        @endforeach
    </div>

    {{-- ───────── Manage ───────── --}}
    @if (! empty($tiles))
        <div class="mb-3 flex items-center gap-2">
            <h2 class="text-xs font-bold uppercase tracking-wider text-chrome-500">{{ __('Manage') }}</h2>
            <span class="h-px flex-1 bg-chrome-200"></span>
        </div>
        @include('partials.module-tiles', ['tiles' => $tiles])
    @endif
</div>
