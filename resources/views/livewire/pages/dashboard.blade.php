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
            ['Installed apps', $appCount, 'bg-primary-600'],
            ['Installed modules', $moduleCount, 'bg-emerald-600'],
            ['Registered models', $modelCount, 'bg-sky-600'],
        ] as [$label, $value, $color])
            <div class="flex items-center gap-4 rounded-xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/5">
                <span class="flex size-10 items-center justify-center rounded-lg {{ $color }} text-white">
                    <svg class="size-5" viewBox="0 0 20 20" fill="currentColor"><path d="M3 4h14v3H3V4Zm0 5h14v3H3V9Zm0 5h14v3H3v-3Z"/></svg>
                </span>
                <div>
                    <p class="text-2xl font-bold text-chrome-900">{{ $value }}</p>
                    <p class="text-xs uppercase tracking-wide text-chrome-400">{{ __($label) }}</p>
                </div>
            </div>
        @endforeach
    </div>

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
