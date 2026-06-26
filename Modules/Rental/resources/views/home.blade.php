<div class="mx-auto max-w-7xl p-4 sm:p-6">
    @php
        // Reusable Heroicons (mini, 0–20 viewBox) used across the cards.
        $ic = [
            'car' => '<path d="M3 9.5 4.2 6.6A2 2 0 0 1 6 5.5h8a2 2 0 0 1 1.8 1.1L17 9.5a2 2 0 0 1 1 1.7V13a1 1 0 0 1-1 1h-1a2 2 0 1 1-4 0H8a2 2 0 1 1-4 0H3a1 1 0 0 1-1-1v-1.8a2 2 0 0 1 1-1.7Z"/><circle cx="6.5" cy="14" r="1.5"/><circle cx="13.5" cy="14" r="1.5"/>',
            'doc' => '<path fill-rule="evenodd" d="M4.5 2A1.5 1.5 0 0 0 3 3.5v13A1.5 1.5 0 0 0 4.5 18h11a1.5 1.5 0 0 0 1.5-1.5V7.621a1.5 1.5 0 0 0-.44-1.06l-3.62-3.622A1.5 1.5 0 0 0 11.378 2H4.5Zm2.25 8.5a.75.75 0 0 0 0 1.5h6.5a.75.75 0 0 0 0-1.5h-6.5Zm0 3a.75.75 0 0 0 0 1.5h6.5a.75.75 0 0 0 0-1.5h-6.5Z" clip-rule="evenodd"/>',
            'clock' => '<path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm.75-13a.75.75 0 0 0-1.5 0v5c0 .414.336.75.75.75h4a.75.75 0 0 0 0-1.5h-3.25V5Z" clip-rule="evenodd"/>',
            'calendar' => '<path fill-rule="evenodd" d="M5.75 2a.75.75 0 0 1 .75.75V4h7V2.75a.75.75 0 0 1 1.5 0V4h.25A2.75 2.75 0 0 1 18 6.75v8.5A2.75 2.75 0 0 1 15.25 18H4.75A2.75 2.75 0 0 1 2 15.25v-8.5A2.75 2.75 0 0 1 4.75 4H5V2.75A.75.75 0 0 1 5.75 2ZM3.5 8.5v6.75c0 .69.56 1.25 1.25 1.25h10.5c.69 0 1.25-.56 1.25-1.25V8.5h-13Z" clip-rule="evenodd"/>',
            'alert' => '<path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.515 2.625H3.72c-1.345 0-2.188-1.458-1.515-2.625L8.485 2.495ZM10 5a.75.75 0 0 1 .75.75v3.5a.75.75 0 0 1-1.5 0v-3.5A.75.75 0 0 1 10 5Zm0 9a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" clip-rule="evenodd"/>',
            'cash' => '<path d="M1 4.25C1 3.56 1.56 3 2.25 3h15.5c.69 0 1.25.56 1.25 1.25v8.5c0 .69-.56 1.25-1.25 1.25H2.25C1.56 14 1 13.44 1 12.75v-8.5ZM10 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM4.25 6.5a1 1 0 1 1-2 0 1 1 0 0 1 2 0ZM17.75 13.5a1 1 0 1 0-2 0 1 1 0 0 0 2 0Z"/><path d="M2.673 16.5a.75.75 0 0 1 .904-.552 24.6 24.6 0 0 0 12.846 0 .75.75 0 1 1 .352 1.458 26.1 26.1 0 0 1-13.55 0 .75.75 0 0 1-.552-.906Z"/>',
            'check' => '<path fill-rule="evenodd" d="M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0Zm-3.97-3.03a.75.75 0 0 0-1.08.022L9.477 12.4l-1.92-1.92a.75.75 0 1 0-1.06 1.06l2.5 2.5a.75.75 0 0 0 1.08-.022l3.992-4.99a.75.75 0 0 0-.02-1.06Z" clip-rule="evenodd"/>',
            'key' => '<path fill-rule="evenodd" d="M8 7a5 5 0 1 1 3.61 4.804l-1.903 1.903A1 1 0 0 1 9 14H8v1a1 1 0 0 1-1 1H6v1a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1v-2a1 1 0 0 1 .293-.707L8.196 8.39A5.002 5.002 0 0 1 8 7Zm5-3a.75.75 0 0 0 0 1.5A1.5 1.5 0 0 1 14.5 7 .75.75 0 0 0 16 7a3 3 0 0 0-3-3Z" clip-rule="evenodd"/>',
            'wrench' => '<path fill-rule="evenodd" d="M14.5 10a4.5 4.5 0 0 0 4.284-5.882c-.105-.324-.51-.391-.752-.15L15.34 6.66a.454.454 0 0 1-.493.11 3.01 3.01 0 0 1-1.618-1.616.455.455 0 0 1 .11-.494l2.694-2.692c.24-.241.174-.647-.15-.752a4.5 4.5 0 0 0-5.873 4.575c.055.873-.128 1.809-.8 2.368l-7.23 6.024a2.724 2.724 0 1 0 3.837 3.837l6.024-7.23c.56-.672 1.495-.855 2.368-.8.096.007.193.01.291.01Z" clip-rule="evenodd"/>',
            'bookmark' => '<path d="M5 4a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v14l-5-2.5L5 18V4Z"/>',
            'plus' => '<path d="M10 5a1 1 0 0 1 1 1v3h3a1 1 0 1 1 0 2h-3v3a1 1 0 1 1-2 0v-3H6a1 1 0 1 1 0-2h3V6a1 1 0 0 1 1-1Z"/>',
        ];
        $tile = 'rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/[0.06] transition hover:-translate-y-0.5 hover:shadow-md';
    @endphp

    {{-- ───────── Hero ───────── --}}
    <div class="relative mb-6 overflow-hidden rounded-3xl bg-gradient-to-br from-chrome-900 via-chrome-900 to-chrome-800 p-6 shadow-pop sm:p-8">
        {{-- Brand glow + watermark --}}
        <div class="pointer-events-none absolute -right-10 -top-16 size-72 rounded-full bg-primary-400/20 blur-3xl"></div>
        <svg class="pointer-events-none absolute -bottom-8 right-6 size-56 text-white/[0.04]" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">{!! $ic['car'] !!}</svg>

        <div class="relative flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <span class="inline-flex items-center gap-1.5 rounded-full bg-primary-400/15 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wider text-primary-300 ring-1 ring-primary-400/20">
                    <span class="size-1.5 rounded-full bg-primary-400"></span>{{ __('Operations') }}
                </span>
                <h1 class="mt-3 text-2xl font-bold tracking-tight text-white sm:text-3xl">{{ __('Rent A Car') }}</h1>
                <p class="mt-1 text-sm text-white/55">{{ __('Fleet, bookings & revenue at a glance.') }}</p>

                {{-- Live mini-stats --}}
                <div class="mt-5 grid max-w-md grid-cols-3 gap-3">
                    @php
                        $glance = [
                            ['label' => __('Active'), 'value' => $activeOrders, 'dot' => 'bg-sky-400'],
                            ['label' => __('Returns due'), 'value' => $returnsDue, 'dot' => 'bg-amber-400'],
                            ['label' => __('Available'), 'value' => $available, 'dot' => 'bg-emerald-400'],
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

            {{-- Actions --}}
            <div class="flex flex-col items-stretch gap-3 lg:items-end">
                <a href="{{ url('/app/rental/order/new') }}" wire:navigate class="o-btn-primary justify-center px-4 py-2.5 text-sm shadow-lg shadow-primary-400/20">
                    <svg class="size-4" viewBox="0 0 20 20" fill="currentColor">{!! $ic['plus'] !!}</svg>{{ __('New order') }}
                </a>
                <div class="flex flex-wrap gap-2 lg:justify-end">
                    @foreach ([['Orders', '/app/rental/order'], ['Quotations', '/app/rental/quotation'], ['Invoices', '/app/rental/invoice'], ['Receipts', '/app/rental/receipt'], ['Replacements', '/app/rental/replacement'], ['Maintenance', '/app/rental/maintenance'], ['Reports', '/app/rental/reports']] as [$lbl, $href])
                        <a href="{{ url($href) }}" wire:navigate class="rounded-lg bg-white/10 px-3 py-1.5 text-xs font-medium text-white/90 ring-1 ring-white/10 transition hover:bg-white/20">{{ __($lbl) }}</a>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    {{-- ───────── Renewal reminder ───────── --}}
    @if ($renewalAlerts->isNotEmpty())
        <div class="mb-6 overflow-hidden rounded-2xl border border-amber-200 bg-amber-50/60 shadow-sm">
            <div class="flex items-center gap-2 border-b border-amber-200/70 px-4 py-3">
                <span class="flex size-7 items-center justify-center rounded-lg bg-amber-100 text-amber-600">
                    <svg class="size-4" viewBox="0 0 20 20" fill="currentColor">{!! $ic['alert'] !!}</svg>
                </span>
                <h2 class="text-sm font-semibold text-amber-900">{{ __('Car papers needing attention') }}</h2>
                <span class="ms-auto rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-bold text-amber-700">{{ $renewalAlerts->count() }}</span>
            </div>
            <ul class="divide-y divide-amber-200/50">
                @foreach ($renewalAlerts as $car)
                    @php
                        $expired = $car->needsRenewal();
                        $rows = [];
                        foreach ([['label' => __('Registration'), 'date' => $car->registration_expiry], ['label' => __('Insurance'), 'date' => $car->insurance_expiry]] as $r) {
                            if ($r['date'] === null) {
                                $rows[] = $r['label'] . ' — ' . __('missing');
                            } elseif ($r['date']->isPast()) {
                                $rows[] = $r['label'] . ' — ' . __('expired :date', ['date' => $r['date']->format('Y-m-d')]);
                            } elseif ($r['date']->lte(now()->addDays(\Modules\Rental\Models\Vehicle::RENEWAL_REMINDER_DAYS))) {
                                $rows[] = $r['label'] . ' — ' . __('expires :date', ['date' => $r['date']->format('Y-m-d')]);
                            }
                        }
                    @endphp
                    <li class="flex items-center justify-between gap-3 px-4 py-2.5">
                        <a href="{{ url('/app/rental/vehicle/' . $car->id) }}" wire:navigate class="font-medium text-chrome-800 hover:text-primary-700">{{ $car->displayName() }}</a>
                        <div class="flex flex-wrap items-center justify-end gap-2 text-xs">
                            <span class="text-chrome-600">{{ implode(' · ', $rows) }}</span>
                            <span class="rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide {{ $expired ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700' }}">{{ $expired ? __('Expired — not bookable') : __('Renew soon') }}</span>
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- ───────── Orders ───────── --}}
    <div class="mb-3 flex items-center gap-2">
        <h2 class="text-xs font-bold uppercase tracking-wider text-chrome-500">{{ __('Orders') }}</h2>
        <span class="h-px flex-1 bg-chrome-200"></span>
    </div>
    <div class="mb-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
        @php
            $orderCards = [
                ['label' => __('Active Orders'), 'value' => $activeOrders, 'href' => url('/app/rental/order?tab=active'), 'icon' => 'doc', 'tint' => 'bg-sky-50 text-sky-600 ring-sky-100'],
                ['label' => __('Pending (Draft)'), 'value' => $draftOrders, 'href' => url('/app/rental/order?tab=draft'), 'icon' => 'clock', 'tint' => 'bg-chrome-100 text-chrome-600 ring-chrome-200'],
                ['label' => __('Returns Due'), 'value' => $returnsDue, 'href' => url('/app/rental/order?tab=active'), 'icon' => 'calendar', 'tint' => 'bg-amber-50 text-amber-600 ring-amber-100'],
                ['label' => __('Unpaid Orders'), 'value' => $unpaidOrders, 'href' => url('/app/rental/order'), 'icon' => 'alert', 'tint' => 'bg-red-50 text-red-600 ring-red-100'],
            ];
        @endphp
        @foreach ($orderCards as $card)
            <a href="{{ $card['href'] }}" wire:navigate class="{{ $tile }} hover:ring-primary-300">
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
        <div class="{{ $tile }} bg-gradient-to-br from-emerald-600 to-emerald-700 ring-0">
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

    {{-- ───────── Fleet status ───────── --}}
    <div class="mb-3 flex items-center gap-2">
        <h2 class="text-xs font-bold uppercase tracking-wider text-chrome-500">{{ __('Fleet status') }}</h2>
        <span class="h-px flex-1 bg-chrome-200"></span>
    </div>
    <div class="mb-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
        @php
            $cards = [
                ['label' => __('Total Fleet'), 'value' => $total, 'icon' => 'car', 'tint' => 'bg-chrome-100 text-chrome-700 ring-chrome-200'],
                ['label' => __('Available'), 'value' => $available, 'icon' => 'check', 'tint' => 'bg-emerald-50 text-emerald-600 ring-emerald-100'],
                ['label' => __('Rented'), 'value' => $rented, 'icon' => 'key', 'tint' => 'bg-sky-50 text-sky-600 ring-sky-100'],
                ['label' => __('Under Maintenance'), 'value' => $maintenance, 'icon' => 'wrench', 'tint' => 'bg-amber-50 text-amber-600 ring-amber-100'],
                ['label' => __('Reserved'), 'value' => $reserved, 'icon' => 'bookmark', 'tint' => 'bg-violet-50 text-violet-600 ring-violet-100'],
            ];
        @endphp
        @foreach ($cards as $card)
            <div class="{{ $tile }}">
                <div class="flex items-center justify-between">
                    <span class="flex size-9 items-center justify-center rounded-xl ring-1 {{ $card['tint'] }}">
                        <svg class="size-5" viewBox="0 0 20 20" fill="currentColor">{!! $ic[$card['icon']] !!}</svg>
                    </span>
                </div>
                <div class="mt-3 text-3xl font-bold tracking-tight text-chrome-900">{{ $card['value'] }}</div>
                <div class="text-sm font-medium text-chrome-500">{{ $card['label'] }}</div>
            </div>
        @endforeach
    </div>

    {{-- ───────── Availability by branch ───────── --}}
    @if ($branches->isNotEmpty())
        <div class="mb-3 flex items-center gap-2">
            <h2 class="text-xs font-bold uppercase tracking-wider text-chrome-500">{{ __('Availability by branch') }}</h2>
            <span class="h-px flex-1 bg-chrome-200"></span>
        </div>
        <div class="mb-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($branches as $branch)
                @php $pct = $branch->total_count > 0 ? round(($branch->available_count / $branch->total_count) * 100) : 0; @endphp
                <div wire:key="branch-{{ $branch->id }}" class="{{ $tile }}">
                    <div class="flex items-center justify-between gap-2">
                        <h3 class="truncate text-sm font-semibold text-chrome-800">{{ $branch->name }}</h3>
                        <span class="shrink-0 rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-emerald-600 ring-1 ring-emerald-100">{{ $pct }}%</span>
                    </div>
                    <div class="mt-3 flex items-end justify-between">
                        <div class="text-3xl font-bold tracking-tight text-chrome-900">{{ $branch->available_count }}<span class="text-base font-medium text-chrome-300"> / {{ $branch->total_count }}</span></div>
                        <div class="text-xs text-chrome-400">{{ __('Cars available') }}</div>
                    </div>
                    <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-chrome-100">
                        <div class="h-full rounded-full bg-emerald-500" style="width: {{ $pct }}%"></div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- ───────── Masters ───────── --}}
    @if (! empty($tiles))
        <div class="mb-3 flex items-center gap-2">
            <h2 class="text-xs font-bold uppercase tracking-wider text-chrome-500">{{ __('Manage') }}</h2>
            <span class="h-px flex-1 bg-chrome-200"></span>
        </div>
        @include('partials.module-tiles', ['tiles' => $tiles])
    @endif
</div>
