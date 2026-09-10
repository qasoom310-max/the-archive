<div class="mx-auto max-w-7xl p-4 sm:p-6">
    <div class="mb-6">
        <h1 class="text-xl font-bold text-chrome-900">{{ __('Dashboard') }}</h1>
        <p class="text-sm text-chrome-500">{{ __('Welcome back.') }}
            {{ __('Press') }}
            <kbd class="rounded border border-chrome-300 bg-chrome-100 px-1 text-xs">⌘K</kbd>
            {{ __('to jump anywhere.') }}</p>
    </div>

    {{-- Apps — one big button per installed app the business type allows
         (Rent A Car, Limousine, Point of Sale, …). The fastest path from the
         dashboard into the app you actually use. --}}
    @if ($apps->isNotEmpty())
        <div class="mb-6 rounded-xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5">
            <h2 class="mb-4 text-sm font-semibold text-chrome-800">{{ __('Your apps') }}</h2>
            {{-- Two rows: the transport apps (Rent A Car | Limousine) pinned
                 2-up on top, the rest 3-up beneath. On a non-transport business
                 `featuredApps` is empty and everything flows in the lower grid. --}}
            @php
                $groups = [
                    ['apps' => $featuredApps, 'cols' => 'sm:grid-cols-2', 'mt' => ''],
                    ['apps' => $otherApps, 'cols' => 'sm:grid-cols-3', 'mt' => $featuredApps->isNotEmpty() ? 'mt-3' : ''],
                ];
            @endphp
            @foreach ($groups as $group)
                @continue ($group['apps']->isEmpty())
                <div class="grid grid-cols-1 gap-3 {{ $group['cols'] }} {{ $group['mt'] }}">
                    @foreach ($group['apps'] as $app)
                        @php
                            // Prefer a registry translation by module slug (POS → نقطة البيع),
                            // else the server-stored display_name — same rule as the app bar.
                            $moduleKey = 'module.' . $app->name;
                            $label = __($moduleKey);
                            if ($label === $moduleKey) { $label = $app->display_name; }
                        @endphp
                        <a href="{{ url('/app/' . $app->name) }}" wire:navigate
                            class="group flex items-center gap-3 rounded-xl border border-chrome-200 bg-white p-4 transition hover:border-primary-400 hover:bg-primary-50/40 hover:shadow-sm">
                            <span class="flex size-11 shrink-0 items-center justify-center rounded-lg bg-primary-400 text-chrome-900">
                                <svg class="size-6" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    {!! \App\Erp\Navigation\ModuleIcon::body($app->name) !!}
                                </svg>
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-semibold text-chrome-800">{{ $label }}</span>
                                <span class="block text-xs text-chrome-400">{{ __('Open') }}</span>
                            </span>
                            <svg class="size-4 shrink-0 text-chrome-300 transition group-hover:text-primary-600 rtl:rotate-180" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.168 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd"/></svg>
                        </a>
                    @endforeach
                </div>
            @endforeach
        </div>
    @endif

    {{-- KPI tiles — system internals, super-admin only. --}}
    @if ($isSuperAdmin)
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
    @endif

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

    {{-- Owner's reports & team — quick-access tiles --}}
    @if ($isAdmin)
        @php
            $ownerTiles = [
                ['url' => '/fares', 'label' => __('Fares'), 'sub' => __('Prices the website shows'),
                 'icon' => 'M5.5 3A2.5 2.5 0 0 0 3 5.5v2.879a2.5 2.5 0 0 0 .732 1.767l6.5 6.5a2.5 2.5 0 0 0 3.536 0l2.878-2.878a2.5 2.5 0 0 0 0-3.536l-6.5-6.5A2.5 2.5 0 0 0 8.38 3H5.5ZM6 7a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z'],
                ['url' => '/calendar', 'label' => __('Ad calendar'), 'sub' => __('When to advertise'),
                 'icon' => 'M5.75 2a.75.75 0 0 1 .75.75V4h7V2.75a.75.75 0 0 1 1.5 0V4h.25A2.75 2.75 0 0 1 18 6.75v8.5A2.75 2.75 0 0 1 15.25 18H4.75A2.75 2.75 0 0 1 2 15.25v-8.5A2.75 2.75 0 0 1 4.75 4H5V2.75A.75.75 0 0 1 5.75 2ZM3.5 8.5v6.75c0 .69.56 1.25 1.25 1.25h10.5c.69 0 1.25-.56 1.25-1.25V8.5h-13Z'],
                ['url' => '/reports/daily-summary', 'label' => __('Daily Summary'), 'sub' => __('Cash in vs out'),
                 'icon' => 'M13.2 2.24a.75.75 0 0 0 .04 1.06l2.1 1.95H6.75a.75.75 0 0 0 0 1.5h8.59l-2.1 1.95a.75.75 0 1 0 1.02 1.1l3.5-3.25a.75.75 0 0 0 0-1.1l-3.5-3.25a.75.75 0 0 0-1.06.04Zm-6.4 8a.75.75 0 0 0-1.06-.04l-3.5 3.25a.75.75 0 0 0 0 1.1l3.5 3.25a.75.75 0 1 0 1.02-1.1l-2.1-1.95h8.59a.75.75 0 0 0 0-1.5H4.66l2.1-1.95a.75.75 0 0 0 .04-1.06Z'],
                ['url' => '/reports/profit', 'label' => __('Profit & Expenses'), 'sub' => __('Real monthly profit'),
                 'icon' => 'M15.5 2A1.5 1.5 0 0 0 14 3.5v13a1.5 1.5 0 0 0 1.5 1.5h.5a1.5 1.5 0 0 0 1.5-1.5v-13A1.5 1.5 0 0 0 16 2h-.5ZM9.5 6A1.5 1.5 0 0 0 8 7.5v9A1.5 1.5 0 0 0 9.5 18h.5a1.5 1.5 0 0 0 1.5-1.5v-9A1.5 1.5 0 0 0 10 6h-.5ZM3.5 10A1.5 1.5 0 0 0 2 11.5v5A1.5 1.5 0 0 0 3.5 18h.5a1.5 1.5 0 0 0 1.5-1.5v-5A1.5 1.5 0 0 0 4 10h-.5Z'],
                ['url' => '/hr/employees', 'label' => __('Employees'), 'sub' => __('Staff & contracts'),
                 'icon' => 'M7 8a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm5.5 1a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5ZM1.6 16.5a5.4 5.4 0 0 1 10.8 0 .5.5 0 0 1-.5.5H2.1a.5.5 0 0 1-.5-.5Zm11.9.5a6.9 6.9 0 0 0-1.2-3.9 4 4 0 0 1 5.1 3.4.5.5 0 0 1-.5.5h-3.4Z'],
                ['url' => '/hr/payroll', 'label' => __('Payroll'), 'sub' => __('Salaries & overtime'),
                 'icon' => 'M1 5.5A1.5 1.5 0 0 1 2.5 4h15A1.5 1.5 0 0 1 19 5.5v9a1.5 1.5 0 0 1-1.5 1.5h-15A1.5 1.5 0 0 1 1 14.5v-9ZM10 13a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM4.5 8a1 1 0 1 1-2 0 1 1 0 0 1 2 0Zm13 4a1 1 0 1 1-2 0 1 1 0 0 1 2 0Z'],
            ];
        @endphp
        <div class="mb-6 rounded-xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5">
            <h2 class="mb-4 flex items-center gap-2 text-sm font-semibold text-chrome-800">
                <span class="flex size-7 items-center justify-center rounded-lg bg-primary-400 text-chrome-900">
                    <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path d="M4.25 2A2.25 2.25 0 0 0 2 4.25v2.5A2.25 2.25 0 0 0 4.25 9h2.5A2.25 2.25 0 0 0 9 6.75v-2.5A2.25 2.25 0 0 0 6.75 2h-2.5Zm0 9A2.25 2.25 0 0 0 2 13.25v2.5A2.25 2.25 0 0 0 4.25 18h2.5A2.25 2.25 0 0 0 9 15.75v-2.5A2.25 2.25 0 0 0 6.75 11h-2.5Zm9-9A2.25 2.25 0 0 0 11 4.25v2.5A2.25 2.25 0 0 0 13.25 9h2.5A2.25 2.25 0 0 0 18 6.75v-2.5A2.25 2.25 0 0 0 15.75 2h-2.5Zm0 9A2.25 2.25 0 0 0 11 13.25v2.5A2.25 2.25 0 0 0 13.25 18h2.5A2.25 2.25 0 0 0 18 15.75v-2.5A2.25 2.25 0 0 0 15.75 11h-2.5Z"/></svg>
                </span>
                {{ __('Reports & Team') }}
            </h2>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($ownerTiles as $t)
                    <a href="{{ url($t['url']) }}" wire:navigate
                        class="group flex items-center gap-3 rounded-xl border border-chrome-200 bg-white p-4 transition hover:border-primary-400 hover:bg-primary-50/40 hover:shadow-sm">
                        <span class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary-400 text-chrome-900">
                            <svg class="size-5" viewBox="0 0 20 20" fill="currentColor"><path d="{{ $t['icon'] }}"/></svg>
                        </span>
                        <span class="min-w-0">
                            <span class="block text-sm font-semibold text-chrome-800">{{ $t['label'] }}</span>
                            <span class="block truncate text-xs text-chrome-400">{{ $t['sub'] }}</span>
                        </span>
                        <svg class="ms-auto size-4 shrink-0 text-chrome-300 transition group-hover:text-primary-600 rtl:rotate-180" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.168 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd"/></svg>
                    </a>
                @endforeach
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
