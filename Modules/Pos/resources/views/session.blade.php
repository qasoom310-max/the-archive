@php $money = fn ($v) => \App\Erp\Money\Currencies::format($v); @endphp

<div class="mx-auto max-w-6xl p-4 sm:p-6">
    <div class="mb-4 flex items-center gap-2 text-sm text-chrome-500">
        <a href="{{ url('/app/pos') }}" wire:navigate class="hover:text-primary-700">{{ __('Point of Sale') }}</a>
        <span>/</span>
        <span class="font-medium text-chrome-700">{{ $session->reference }}</span>
        <span class="o-chip ms-2 bg-{{ $session->state->color() }}-50 text-{{ $session->state->color() }}-700">
            {{ __($session->state->label()) }}
        </span>
        <span class="ms-auto">
            @include('pos::partials.user-chip', ['user' => $session->user, 'sub' => __('Opened by')])
        </span>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            {{-- KPIs — 3 across, but the money values shrink and wrap instead of
                 overflowing their ~100px column on a phone (register-close screen). --}}
            <div class="grid grid-cols-3 gap-2 sm:gap-4">
                @foreach ([[__('Orders'), $ordersCount], [__('Sales'), $money($salesTotal)], [__('Expected cash'), $money($expectedCash)]] as [$label, $value])
                    <div class="rounded-xl bg-white p-3 shadow-sm ring-1 ring-chrome-900/5 sm:p-4">
                        <p class="break-words text-lg font-bold leading-tight tabular-nums text-chrome-900 sm:text-2xl">{{ $value }}</p>
                        <p class="mt-1 text-xs uppercase tracking-wide text-chrome-400">{{ $label }}</p>
                    </div>
                @endforeach
            </div>

            {{-- Payment breakdown --}}
            <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/5">
                <h2 class="mb-2 text-sm font-semibold text-chrome-800">{{ __('Payments by method') }}</h2>
                @forelse ($byMethod as $name => $amount)
                    <div class="flex justify-between border-b border-chrome-100 py-1.5 text-sm">
                        <span class="text-chrome-600">{{ $name }}</span>
                        <span class="font-medium">{{ $money($amount) }}</span>
                    </div>
                @empty
                    <p class="py-4 text-center text-sm text-chrome-400">{{ __('No payments yet.') }}</p>
                @endforelse
            </div>

            {{-- Orders --}}
            <div class="rounded-xl bg-white shadow-sm ring-1 ring-chrome-900/5">
                <div class="border-b border-chrome-200 px-4 py-2 text-sm font-semibold text-chrome-800">{{ __('Orders') }}</div>
                {{-- overflow-x-auto so 5-col table scrolls on phone, not the page. --}}
                <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-chrome-100 text-sm">
                    <tbody class="divide-y divide-chrome-100">
                        @forelse ($orders as $o)
                            <tr class="hover:bg-chrome-50">
                                <td class="px-4 py-2 font-medium">{{ $o->reference }}</td>
                                <td class="px-4 py-2 text-chrome-500">{{ $o->user?->name ?? '—' }}</td>
                                <td class="px-4 py-2"><span class="o-chip bg-{{ $o->state->color() }}-50 text-{{ $o->state->color() }}-700">{{ __($o->state->label()) }}</span></td>
                                <td class="px-4 py-2 text-end">{{ $money($o->total) }}</td>
                                <td class="px-4 py-2 text-end text-chrome-400">{{ $o->ordered_at?->isoFormat('HH:mm') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-6 text-center text-sm text-chrome-400">{{ __('No finalised orders.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
                </div>
            </div>

            {{-- Purchases recorded during this session (Purchases module). --}}
            @if ($purchases->isNotEmpty())
                @php $pcolor = ['confirmed' => 'emerald', 'draft' => 'amber', 'cancelled' => 'red']; @endphp
                <div class="rounded-xl bg-white shadow-sm ring-1 ring-chrome-900/5">
                    <div class="flex items-center justify-between border-b border-chrome-200 px-4 py-2">
                        <span class="text-sm font-semibold text-chrome-800">{{ __('Purchases') }}</span>
                        <span class="text-sm font-semibold text-chrome-700">{{ $money($purchasesTotal) }}</span>
                    </div>
                    <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-chrome-100 text-sm">
                        <tbody class="divide-y divide-chrome-100">
                            @foreach ($purchases as $p)
                                <tr class="hover:bg-chrome-50 cursor-pointer" onclick="window.location='{{ url('/app/purchases/purchase/' . $p->id) }}'">
                                    <td class="px-4 py-2 font-medium text-primary-700">{{ $p->reference ?? ('#' . $p->id) }}</td>
                                    <td class="px-4 py-2 text-chrome-500">{{ $p->name ?? '—' }}</td>
                                    <td class="px-4 py-2"><span class="o-chip bg-{{ $pcolor[$p->state] ?? 'chrome' }}-50 text-{{ $pcolor[$p->state] ?? 'chrome' }}-700">{{ __(ucfirst($p->state)) }}</span></td>
                                    <td class="px-4 py-2 text-end">{{ $money($p->total) }}</td>
                                    <td class="px-4 py-2 text-end text-chrome-400">{{ \Illuminate\Support\Carbon::parse($p->date)->isoFormat('MMM D') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    </div>
                </div>
            @endif
        </div>

        {{-- On phones/tablets the register controls come first (order-first);
             on desktop this returns to the right-hand column. --}}
        <div class="space-y-6 order-first lg:order-none">
            {{-- Close / reconciliation --}}
            <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/5">
                <h2 class="mb-2 text-sm font-semibold text-chrome-800">{{ __('Cash control') }}</h2>
                @if ($session->isOpen())
                    <div class="flex justify-between text-sm text-chrome-500">
                        <span>{{ __('Opening float') }}</span><span>{{ $money($session->opening_cash) }}</span>
                    </div>
                    <div class="flex justify-between text-sm text-chrome-500">
                        <span>{{ __('Expected in drawer') }}</span><span>{{ $money($expectedCash) }}</span>
                    </div>
                    @if ($canClose)
                        <label class="mt-3 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Counted cash') }}</label>
                        <input type="number" step="0.01" wire:model="countedCash" class="o-input mt-1">
                        <button wire:click="closeSession"
                            wire:confirm="{{ __('Close the shared register? This finalises it for all cashiers and cannot be reopened.') }}"
                            class="o-btn-primary mt-3 w-full justify-center">{{ __('Close register') }}</button>
                    @else
                        <p class="mt-3 rounded-lg bg-chrome-50 px-3 py-2 text-xs text-chrome-500">
                            {{ __('Only a manager can close the shared register.') }}
                        </p>
                    @endif
                @else
                    <div class="space-y-1 text-sm">
                        <div class="flex justify-between text-chrome-500"><span>{{ __('Expected') }}</span><span>{{ $money($session->expected_cash) }}</span></div>
                        <div class="flex justify-between text-chrome-500"><span>{{ __('Counted') }}</span><span>{{ $money($session->closing_cash) }}</span></div>
                        <div class="flex justify-between font-bold {{ ($session->cash_difference ?? 0) == 0 ? 'text-emerald-600' : 'text-red-600' }}">
                            <span>{{ __('Difference') }}</span><span>{{ $money($session->cash_difference) }}</span>
                        </div>
                        <p class="pt-1 text-xs text-chrome-400">{{ __('Closed') }} {{ $session->closed_at?->isoFormat('MMM D, YYYY HH:mm') }}</p>
                    </div>
                @endif
            </div>

            <div wire:poll.30s class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/5">
                <h2 class="mb-3 text-sm font-semibold text-chrome-800">{{ __('Active cashiers') }}</h2>
                @forelse ($participants as $p)
                    <div wire:key="sp-{{ $p->id }}"
                        class="flex items-center justify-between border-b border-chrome-100 py-2 last:border-0">
                        @include('pos::partials.user-chip', ['user' => $p->user])
                        <span class="text-xs text-chrome-400">{{ $p->last_activity?->diffForHumans() }}</span>
                    </div>
                @empty
                    <p class="py-3 text-center text-sm text-chrome-400">{{ __('No one is on the register right now.') }}</p>
                @endforelse
            </div>

            <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/5">
                <h2 class="mb-3 text-sm font-semibold text-chrome-800">{{ __('Chatter') }}</h2>
                <livewire:chatter :record="$session" :key="'pos-session-chatter-' . $session->id" />
            </div>
        </div>
    </div>
</div>
