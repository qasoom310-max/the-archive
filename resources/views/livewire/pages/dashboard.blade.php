<div class="mx-auto max-w-7xl p-4 sm:p-6">
    <div class="mb-6">
        <h1 class="text-xl font-bold text-chrome-900">{{ __('Dashboard') }}</h1>
        <p class="text-sm text-chrome-500">{{ __('Welcome back.') }}
            {{ __('Press') }}
            <kbd class="rounded border border-chrome-300 bg-chrome-100 px-1 text-xs">⌘K</kbd>
            {{ __('to jump anywhere.') }}</p>
    </div>

    {{-- KPI tiles --}}
    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        @foreach ([
            ['Installed apps', $appCount, 'bg-primary-400', 'text-chrome-900'],
            ['Installed modules', $moduleCount, 'bg-emerald-600', 'text-white'],
            ['Registered models', $modelCount, 'bg-sky-600', 'text-white'],
        ] as [$label, $value, $color, $textColor])
            <div class="flex items-center gap-4 rounded-xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/5">
                <span class="flex size-10 items-center justify-center rounded-lg {{ $color }} {{ $textColor }}">
                    <svg class="size-5" viewBox="0 0 20 20" fill="currentColor"><path d="M3 4h14v3H3V4Zm0 5h14v3H3V9Zm0 5h14v3H3v-3Z"/></svg>
                </span>
                <div>
                    <p class="text-2xl font-bold text-chrome-900">{{ $value }}</p>
                    <p class="text-xs uppercase tracking-wide text-chrome-400">{{ __($label) }}</p>
                </div>
            </div>
        @endforeach
    </div>

    {{-- ───────────── Daily report containers (admin-only) ───────────── --}}
    @if ($isAdmin && $dailySales !== null && $stockSummary !== null)
        <div class="mb-6 grid grid-cols-1 gap-4 lg:grid-cols-2">
            {{-- Daily sale --}}
            <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5">
                <div class="flex items-center justify-between">
                    <h2 class="flex items-center gap-2 text-sm font-semibold text-chrome-800">
                        <span class="flex size-7 items-center justify-center rounded-lg bg-primary-400 text-chrome-900">
                            <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10 1a1 1 0 0 1 1 1v1.06a4 4 0 0 1 3 3.87 1 1 0 1 1-2 0 2 2 0 0 0-1-1.73V9.2l1.2.4A4 4 0 0 1 11 17.94V19a1 1 0 1 1-2 0v-1.06a4 4 0 0 1-3-3.87 1 1 0 1 1 2 0 2 2 0 0 0 1 1.73v-3.13l-1.2-.4A4 4 0 0 1 9 2.06V2a1 1 0 0 1 1-1Z"/></svg>
                        </span>
                        {{ __('Daily sale') }}
                    </h2>
                    <span class="o-chip bg-chrome-100 text-chrome-500">{{ $reportPeriod }}</span>
                </div>
                <div class="mt-4 grid grid-cols-3 gap-3">
                    <div>
                        <p class="text-xs uppercase tracking-wide text-chrome-400">{{ __('Revenue') }}</p>
                        <p class="mt-0.5 text-xl font-bold text-chrome-900">{{ \App\Erp\Money\Currencies::format((float) $dailySales['revenue']) }}</p>
                    </div>
                    <div>
                        <p class="text-xs uppercase tracking-wide text-chrome-400">{{ __('Orders') }}</p>
                        <p class="mt-0.5 text-xl font-bold text-chrome-900">{{ $dailySales['orders'] }}</p>
                    </div>
                    <div>
                        <p class="text-xs uppercase tracking-wide text-chrome-400">{{ __('Avg. order') }}</p>
                        <p class="mt-0.5 text-xl font-bold text-chrome-900">{{ \App\Erp\Money\Currencies::format((float) $dailySales['aov']) }}</p>
                    </div>
                </div>
                <p class="mt-3 text-xs text-chrome-400">
                    {{ __('Tax') }} {{ \App\Erp\Money\Currencies::format((float) $dailySales['tax_total']) }}
                    · {{ __('Discounts') }} {{ \App\Erp\Money\Currencies::format((float) $dailySales['discount_total']) }}
                </p>
                <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1">
                    <a href="{{ url('/reports/daily-summary') }}" wire:navigate
                        class="inline-flex items-center gap-1 text-xs font-medium text-primary-700 hover:underline">
                        {{ __('Daily Summary') }} ({{ __('Sales vs purchases, net cash flow') }}) →
                    </a>
                    <a href="{{ url('/reports/profit') }}" wire:navigate
                        class="inline-flex items-center gap-1 text-xs font-medium text-primary-700 hover:underline">
                        {{ __('Profit & Expenses') }} →
                    </a>
                    <a href="{{ url('/hr/employees') }}" wire:navigate
                        class="inline-flex items-center gap-1 text-xs font-medium text-primary-700 hover:underline">
                        {{ __('Employees') }} →
                    </a>
                    <a href="{{ url('/hr/payroll') }}" wire:navigate
                        class="inline-flex items-center gap-1 text-xs font-medium text-primary-700 hover:underline">
                        {{ __('Payroll') }} →
                    </a>
                </div>
            </div>

            {{-- Daily stock report --}}
            <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5">
                <h2 class="flex items-center gap-2 text-sm font-semibold text-chrome-800">
                    <span class="flex size-7 items-center justify-center rounded-lg bg-emerald-600 text-white">
                        <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path d="M3 4a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1v3a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V4Zm0 6a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1v6a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-6Zm3 2a1 1 0 1 0 0 2h4a1 1 0 1 0 0-2H6Z"/></svg>
                    </span>
                    {{ __('Daily stock report') }}
                </h2>
                <div class="mt-4 grid grid-cols-3 gap-3">
                    <div>
                        <p class="text-xs uppercase tracking-wide text-chrome-400">{{ __('Products') }}</p>
                        <p class="mt-0.5 text-xl font-bold text-chrome-900">{{ $stockSummary['total'] }}</p>
                    </div>
                    <div>
                        <p class="text-xs uppercase tracking-wide text-chrome-400">{{ __('Low') }}</p>
                        <p class="mt-0.5 text-xl font-bold {{ $stockSummary['low_count'] > 0 ? 'text-amber-600' : 'text-chrome-900' }}">{{ $stockSummary['low_count'] }}</p>
                    </div>
                    <div>
                        <p class="text-xs uppercase tracking-wide text-chrome-400">{{ __('Out of stock') }}</p>
                        <p class="mt-0.5 text-xl font-bold {{ $stockSummary['out_count'] > 0 ? 'text-red-600' : 'text-chrome-900' }}">{{ $stockSummary['out_count'] }}</p>
                    </div>
                </div>
                @if ($inventoryValue !== null)
                    <div class="mt-4 flex items-center justify-between border-t border-chrome-100 pt-3">
                        <div>
                            <p class="text-xs uppercase tracking-wide text-chrome-400">{{ __('Inventory value') }}</p>
                            <p class="mt-0.5 text-xl font-bold text-primary-700">{{ \App\Erp\Money\Currencies::format((float) $inventoryValue) }}</p>
                        </div>
                        <a href="{{ url('/app/pos/stock-report') }}" wire:navigate class="o-btn-primary shrink-0">
                            {{ __('Open Stock Report') }} →
                        </a>
                    </div>
                @endif
                <p class="mt-3 text-xs text-chrome-400">{{ __('On-hand × cost. Full breakdown in the Stock Report.') }}</p>
            </div>
        </div>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <section class="lg:col-span-2">
            @if (auth()->user()?->isAdmin())
                {{-- Admin-only: manage per-phone customer discounts. An admin
                     assigns an open discount % to a phone number; when the
                     cashier adds that customer at the register, it applies to
                     the order total automatically. --}}
                <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-chrome-900/5">
                    <div class="flex items-start gap-4">
                        <span class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-emerald-600 text-white">
                            <svg class="size-5" viewBox="0 0 20 20" fill="currentColor"><path d="M17.78 2.22a.75.75 0 0 1 0 1.06l-14.5 14.5a.75.75 0 1 1-1.06-1.06l14.5-14.5a.75.75 0 0 1 1.06 0ZM5.5 7a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Zm9 9a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Z"/></svg>
                        </span>
                        <div class="min-w-0">
                            <h2 class="text-sm font-semibold text-chrome-800">{{ __('Customer Discounts') }}</h2>
                            <p class="mt-1 text-sm text-chrome-500">
                                {{ __('Assign an open discount % to a phone number. When the cashier adds that customer at the register, it comes off the order total.') }}
                            </p>
                            <a href="{{ url('/app/pos/customer_discount') }}" wire:navigate class="o-btn-primary mt-4">
                                {{ __('Manage customer discounts') }} →
                            </a>
                        </div>
                    </div>
                </div>
            @endif
        </section>

        <section>
            <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/5">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-chrome-800">{{ __('Chatter') }}</h2>
                    @if ($ticket)
                        <span class="o-chip bg-chrome-100 text-chrome-500">{{ $ticket->subject }}</span>
                    @endif
                </div>
                @if ($ticket)
                    <livewire:chatter :record="$ticket" :key="'chatter-'.$ticket->id" />
                @else
                    <p class="py-8 text-center text-sm text-chrome-400">
                        {{ __('No demo record.') }} <code class="rounded bg-chrome-100 px-1">php artisan db:seed</code>.
                    </p>
                @endif
            </div>
        </section>
    </div>
</div>
