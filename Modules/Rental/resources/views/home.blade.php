<div class="mx-auto max-w-7xl p-4 sm:p-6">
    <div class="mb-6 flex items-start justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold text-chrome-900">{{ __('Rent A Car') }}</h1>
            <p class="text-sm text-chrome-500">{{ __('Fleet overview & operations.') }}</p>
        </div>
        <div class="flex shrink-0 flex-wrap gap-2">
            <a href="{{ url('/app/rental/quotation') }}" wire:navigate class="o-btn-ghost text-sm">{{ __('Quotations') }}</a>
            <a href="{{ url('/app/rental/order') }}" wire:navigate class="o-btn-ghost text-sm">{{ __('Orders') }}</a>
            <a href="{{ url('/app/rental/invoice') }}" wire:navigate class="o-btn-ghost text-sm">{{ __('Invoices') }}</a>
            <a href="{{ url('/app/rental/receipt') }}" wire:navigate class="o-btn-ghost text-sm">{{ __('Receipts') }}</a>
            <a href="{{ url('/app/rental/replacement') }}" wire:navigate class="o-btn-ghost text-sm">{{ __('Replacements') }}</a>
            <a href="{{ url('/app/rental/maintenance') }}" wire:navigate class="o-btn-ghost text-sm">{{ __('Maintenance') }}</a>
            <a href="{{ url('/app/rental/reports') }}" wire:navigate class="o-btn-ghost text-sm">{{ __('Reports') }}</a>
            <a href="{{ url('/app/rental/order/new') }}" wire:navigate class="o-btn-primary text-sm">{{ __('New order') }}</a>
        </div>
    </div>

    {{-- Reusable car glyph for the cards. --}}
    @php
        $carIcon = '<path d="M3 9.5 4.2 6.6A2 2 0 0 1 6 5.5h8a2 2 0 0 1 1.8 1.1L17 9.5a2 2 0 0 1 1 1.7V13a1 1 0 0 1-1 1h-1a2 2 0 1 1-4 0H8a2 2 0 1 1-4 0H3a1 1 0 0 1-1-1v-1.8a2 2 0 0 1 1-1.7Z"/><circle cx="6.5" cy="14" r="1.5"/><circle cx="13.5" cy="14" r="1.5"/>';
    @endphp

    {{-- Registration / insurance renewal reminder: cars missing, expired, or
         expiring within 30 days. Expired cars are held out of the booking list. --}}
    @if ($renewalAlerts->isNotEmpty())
        <div class="mb-8 rounded-xl border border-amber-200 bg-amber-50 p-4">
            <div class="mb-2 flex items-center gap-2">
                <svg class="size-5 text-amber-600" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.515 2.625H3.72c-1.345 0-2.188-1.458-1.515-2.625L8.485 2.495ZM10 6a.75.75 0 0 1 .75.75v3.5a.75.75 0 0 1-1.5 0v-3.5A.75.75 0 0 1 10 6Zm0 9a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" clip-rule="evenodd"/></svg>
                <h2 class="text-sm font-semibold text-amber-800">{{ __('Car papers needing attention') }}</h2>
            </div>
            <ul class="divide-y divide-amber-200/60">
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
                    <li class="flex items-center justify-between gap-3 py-2">
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

    {{-- Order KPIs --}}
    <h2 class="mb-3 text-sm font-semibold text-chrome-800">{{ __('Orders') }}</h2>
    <div class="mb-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
        @php
            $orderCards = [
                ['label' => __('Active Orders'), 'value' => $activeOrders, 'href' => url('/app/rental/order?tab=active'), 'badge' => __('Active'), 'badgeClass' => 'bg-sky-100 text-sky-700'],
                ['label' => __('Pending (Draft)'), 'value' => $draftOrders, 'href' => url('/app/rental/order?tab=draft'), 'badge' => __('Draft'), 'badgeClass' => 'bg-chrome-200 text-chrome-700'],
                ['label' => __('Returns Due'), 'value' => $returnsDue, 'href' => url('/app/rental/order?tab=active'), 'badge' => __('Due'), 'badgeClass' => 'bg-amber-100 text-amber-700'],
                ['label' => __('Unpaid Orders'), 'value' => $unpaidOrders, 'href' => url('/app/rental/order'), 'badge' => __('Unpaid'), 'badgeClass' => 'bg-red-100 text-red-700'],
            ];
        @endphp
        @foreach ($orderCards as $card)
            <a href="{{ $card['href'] }}" wire:navigate class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5 transition hover:ring-primary-400">
                <div class="flex items-start justify-between gap-2">
                    <h3 class="text-sm font-medium text-chrome-700">{{ $card['label'] }}</h3>
                    <span class="shrink-0 rounded px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide {{ $card['badgeClass'] }}">{{ $card['badge'] }}</span>
                </div>
                <div class="mt-3 text-3xl font-bold text-chrome-800">{{ $card['value'] }}</div>
            </a>
        @endforeach
        <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5">
            <div class="flex items-start justify-between gap-2">
                <h3 class="text-sm font-medium text-chrome-700">{{ __('Revenue') }}</h3>
                <span class="shrink-0 rounded bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-emerald-700">{{ __('Collected') }}</span>
            </div>
            <div class="mt-3 text-2xl font-bold text-chrome-800">{{ \App\Erp\Views\ValueFormat::money($revenue) }}</div>
        </div>
    </div>

    {{-- Per-branch availability --}}
    @if ($branches->isNotEmpty())
        <h2 class="mb-3 text-sm font-semibold text-chrome-800">{{ __('Availability by branch') }}</h2>
        <div class="mb-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($branches as $branch)
                <div wire:key="branch-{{ $branch->id }}"
                    class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5">
                    <div class="flex items-start justify-between gap-2">
                        <h3 class="truncate text-sm font-medium text-chrome-700">{{ $branch->name }}</h3>
                        <span class="shrink-0 rounded bg-sky-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-sky-700">{{ __('Available') }}</span>
                    </div>
                    <div class="mt-3 flex items-end justify-between">
                        <div>
                            <div class="text-3xl font-bold text-chrome-800">{{ $branch->available_count }}<span class="text-base font-medium text-chrome-300"> / {{ $branch->total_count }}</span></div>
                            <div class="text-xs text-chrome-400">{{ __('Cars') }}</div>
                        </div>
                        <svg class="size-7 text-sky-500" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">{!! $carIcon !!}</svg>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Fleet status KPIs --}}
    <h2 class="mb-3 text-sm font-semibold text-chrome-800">{{ __('Fleet status') }}</h2>
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
        @php
            $cards = [
                ['label' => __('Total Fleet'), 'value' => $total, 'badge' => __('Total'), 'badgeClass' => 'bg-emerald-100 text-emerald-700', 'iconClass' => 'text-emerald-500'],
                ['label' => __('Available'), 'value' => $available, 'badge' => __('Available'), 'badgeClass' => 'bg-sky-100 text-sky-700', 'iconClass' => 'text-sky-500'],
                ['label' => __('Rented'), 'value' => $rented, 'badge' => __('Rented'), 'badgeClass' => 'bg-red-100 text-red-700', 'iconClass' => 'text-red-500'],
                ['label' => __('Under Maintenance'), 'value' => $maintenance, 'badge' => __('Maintenance'), 'badgeClass' => 'bg-amber-100 text-amber-700', 'iconClass' => 'text-amber-500'],
                ['label' => __('Reserved'), 'value' => $reserved, 'badge' => __('Reserved'), 'badgeClass' => 'bg-violet-100 text-violet-700', 'iconClass' => 'text-violet-500'],
            ];
        @endphp
        @foreach ($cards as $card)
            <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5">
                <div class="flex items-start justify-between gap-2">
                    <h3 class="text-sm font-medium text-chrome-700">{{ $card['label'] }}</h3>
                    <span class="shrink-0 rounded px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide {{ $card['badgeClass'] }}">{{ $card['badge'] }}</span>
                </div>
                <div class="mt-3 flex items-end justify-between">
                    <div>
                        <div class="text-3xl font-bold text-chrome-800">{{ $card['value'] }}</div>
                        <div class="text-xs text-chrome-400">{{ __('Cars') }}</div>
                    </div>
                    <svg class="size-7 {{ $card['iconClass'] }}" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">{!! $carIcon !!}</svg>
                </div>
            </div>
        @endforeach
    </div>

    {{-- Masters: clickable tiles for Customers / Vehicles / Drivers / Branches. --}}
    @if (! empty($tiles))
        <div class="mt-8">
            <h2 class="mb-3 text-sm font-semibold text-chrome-800">{{ __('Masters') }}</h2>
            @include('partials.module-tiles', ['tiles' => $tiles])
        </div>
    @endif
</div>
