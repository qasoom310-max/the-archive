<div class="mx-auto max-w-7xl p-4 sm:p-6">
    <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold text-chrome-900">{{ __('Limousine') }}</h1>
            <p class="text-sm text-chrome-500">{{ __('Bookings overview.') }}</p>
        </div>
        <div class="flex shrink-0 flex-wrap gap-2">
            <a href="{{ url('/app/limousine/booking') }}" wire:navigate class="o-btn-ghost text-sm">{{ __('Bookings') }}</a>
            <a href="{{ url('/app/limousine/quotation') }}" wire:navigate class="o-btn-ghost text-sm">{{ __('Quotations') }}</a>
            <a href="{{ url('/app/limousine/invoice') }}" wire:navigate class="o-btn-ghost text-sm">{{ __('Invoices') }}</a>
            <a href="{{ url('/app/limousine/receipt') }}" wire:navigate class="o-btn-ghost text-sm">{{ __('Receipts') }}</a>
            <a href="{{ url('/app/limousine/expense') }}" wire:navigate class="o-btn-ghost text-sm">{{ __('Expenses') }}</a>
            <a href="{{ url('/app/limousine/reports') }}" wire:navigate class="o-btn-ghost text-sm">{{ __('Reports') }}</a>
            <a href="{{ url('/app/limousine/booking/new') }}" wire:navigate class="o-btn-primary text-sm">{{ __('New booking') }}</a>
        </div>
    </div>

    @php
        $cars = '<path d="M3 9.5 4.2 6.6A2 2 0 0 1 6 5.5h8a2 2 0 0 1 1.8 1.1L17 9.5a2 2 0 0 1 1 1.7V13a1 1 0 0 1-1 1h-1a2 2 0 1 1-4 0H8a2 2 0 1 1-4 0H3a1 1 0 0 1-1-1v-1.8a2 2 0 0 1 1-1.7Z"/><circle cx="6.5" cy="14" r="1.5"/><circle cx="13.5" cy="14" r="1.5"/>';
        $cards = [
            ['label' => __('Bookings Queue'), 'value' => $queue, 'href' => url('/app/limousine/booking?tab=queue'), 'badge' => __('Queue'), 'badgeClass' => 'bg-amber-100 text-amber-700'],
            ['label' => __('Active Bookings'), 'value' => $active, 'href' => url('/app/limousine/booking?tab=active'), 'badge' => __('Active'), 'badgeClass' => 'bg-indigo-100 text-indigo-700'],
            ['label' => __('Completed Trips'), 'value' => $completed, 'href' => url('/app/limousine/booking?tab=completed'), 'badge' => __('Completed'), 'badgeClass' => 'bg-emerald-100 text-emerald-700'],
            ['label' => __('Unpaid Bookings'), 'value' => $unpaid, 'href' => url('/app/limousine/booking'), 'badge' => __('Unpaid'), 'badgeClass' => 'bg-red-100 text-red-700'],
        ];
        $dayCards = [
            ['label' => __("Yesterday's Bookings"), 'value' => $yesterdayCount, 'badge' => __('Yesterday')],
            ['label' => __("Today's Bookings"), 'value' => $todayCount, 'badge' => __('Today')],
            ['label' => __("Tomorrow's Bookings"), 'value' => $tomorrowCount, 'badge' => __('Tomorrow')],
        ];
    @endphp

    <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($cards as $card)
            <a href="{{ $card['href'] }}" wire:navigate class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5 transition hover:ring-primary-400">
                <div class="flex items-start justify-between gap-2">
                    <h3 class="text-sm font-medium text-chrome-700">{{ $card['label'] }}</h3>
                    <span class="shrink-0 rounded px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide {{ $card['badgeClass'] }}">{{ $card['badge'] }}</span>
                </div>
                <div class="mt-3 flex items-end justify-between">
                    <div class="text-3xl font-bold text-chrome-800">{{ $card['value'] }}</div>
                    <svg class="size-7 text-chrome-300" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">{!! $cars !!}</svg>
                </div>
            </a>
        @endforeach
    </div>

    <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($dayCards as $card)
            <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5">
                <div class="flex items-start justify-between gap-2">
                    <h3 class="text-sm font-medium text-chrome-700">{{ $card['label'] }}</h3>
                    <span class="shrink-0 rounded bg-chrome-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-chrome-600">{{ $card['badge'] }}</span>
                </div>
                <div class="mt-3 text-3xl font-bold text-chrome-800">{{ $card['value'] }}</div>
            </div>
        @endforeach

        <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5">
            <div class="flex items-start justify-between gap-2">
                <h3 class="text-sm font-medium text-chrome-700">{{ __('Revenue') }}</h3>
                <span class="shrink-0 rounded bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-emerald-700">{{ __('Collected') }}</span>
            </div>
            <div class="mt-3 text-2xl font-bold text-chrome-800">{{ \App\Erp\Views\ValueFormat::money($revenue) }}</div>
        </div>
    </div>

    @if (! empty($tiles))
        <div class="mt-8">
            <h2 class="mb-3 text-sm font-semibold text-chrome-800">{{ __('Manage') }}</h2>
            @include('partials.module-tiles', ['tiles' => $tiles])
        </div>
    @endif
</div>
