@php
    $money = fn ($v) => \App\Erp\Money\Currencies::format($v);
    $tabs = [
        ['key' => 'new', 'label' => __('New'), 'count' => $counts['new'] ?? 0],
        ['key' => 'accepted', 'label' => __('Accepted'), 'count' => $counts['accepted'] ?? 0],
        ['key' => 'rejected', 'label' => __('Rejected'), 'count' => $counts['rejected'] ?? 0],
        ['key' => 'all', 'label' => __('All'), 'count' => null],
    ];
    $statusBadge = [
        'new' => 'bg-amber-50 text-amber-700',
        'accepted' => 'bg-emerald-50 text-emerald-700',
        'rejected' => 'bg-red-50 text-red-600',
    ];
    $statusLabel = ['new' => __('New'), 'accepted' => __('Accepted'), 'rejected' => __('Rejected')];
@endphp

<div class="mx-auto max-w-4xl p-4 sm:p-6">
    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold text-chrome-900">{{ __('Website orders') }}</h1>
            <p class="text-sm text-chrome-500">{{ __('Orders placed on the online store. Accept one to send it to Remote sales, paid on delivery.') }}</p>
        </div>
        <div class="flex shrink-0 items-center gap-2">
            <a href="{{ url('/app/pos/website-products') }}" wire:navigate class="o-btn-ghost">{{ __('Website products') }}</a>
            <a href="{{ url('/app/pos/remote') }}" wire:navigate class="o-btn-ghost">{{ __('Remote sales') }}</a>
        </div>
    </div>

    @if ($message !== '')
        <p class="mb-3 rounded-xl bg-emerald-50 px-4 py-2 text-sm text-emerald-800 ring-1 ring-emerald-200">{{ $message }}</p>
    @endif
    @if ($error !== '')
        <p class="mb-3 rounded-xl bg-red-50 px-4 py-2 text-sm text-red-700 ring-1 ring-red-200">{{ $error }}</p>
    @endif

    <div class="mb-4 flex flex-wrap gap-1.5">
        @foreach ($tabs as $tab)
            <button type="button" wire:click="setFilter('{{ $tab['key'] }}')"
                class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-sm font-medium transition-colors {{ $filter === $tab['key'] ? 'bg-primary-500 text-chrome-900' : 'bg-white text-chrome-600 ring-1 ring-chrome-200 hover:bg-chrome-50' }}">
                {{ $tab['label'] }}
                @if ($tab['count'] !== null)
                    <span class="rounded-full px-1.5 text-xs {{ $filter === $tab['key'] ? 'bg-chrome-900/10' : 'bg-chrome-100 text-chrome-500' }}">{{ $tab['count'] }}</span>
                @endif
            </button>
        @endforeach
    </div>

    <div class="space-y-3">
        @forelse ($orders as $order)
            <div wire:key="web-order-{{ $order->id }}" class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/[0.06]">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <span class="font-mono text-sm font-semibold text-chrome-900" dir="ltr">{{ $order->source_reference }}</span>
                            <span class="rounded-full px-2 py-0.5 text-[11px] font-medium {{ $statusBadge[$order->status] ?? 'bg-chrome-100 text-chrome-600' }}">{{ $statusLabel[$order->status] ?? $order->status }}</span>
                        </div>
                        <p class="mt-1 text-sm font-medium text-chrome-800">{{ $order->customer_name }}</p>
                        <p class="text-xs text-chrome-500" dir="ltr">+{{ $order->customer_phone }}</p>
                        <p class="mt-1 whitespace-pre-line text-xs text-chrome-500">{{ $order->delivery_address }}</p>
                        @if ($order->notes)
                            <p class="mt-1 whitespace-pre-line text-xs italic text-chrome-500">{{ $order->notes }}</p>
                        @endif
                    </div>
                    <div class="text-end">
                        <p class="text-base font-bold text-chrome-900">{{ $money($order->total) }}</p>
                        <p class="text-[11px] text-chrome-400">{{ $order->created_at?->diffForHumans() }}</p>
                    </div>
                </div>

                <ul class="mt-3 divide-y divide-chrome-100 border-t border-chrome-100 text-sm">
                    @foreach ($order->lines as $line)
                        <li class="flex justify-between gap-2 py-1.5">
                            <span class="text-chrome-700">{{ $line['qty'] }} × {{ $line['name'] }}</span>
                            <span class="text-chrome-600">{{ $money($line['total']) }}</span>
                        </li>
                    @endforeach
                    <li class="flex justify-between gap-2 py-1.5 text-xs text-chrome-500">
                        <span>{{ __('Delivery charged to the customer') }}</span>
                        <span>{{ $money($order->delivery_charge) }}</span>
                    </li>
                </ul>

                @if ($order->isNew())
                    @if ($rejectingId === $order->id)
                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            <input type="text" wire:model="rejectReason" class="o-input flex-1 text-sm" placeholder="{{ __('Why? (optional, for your records)') }}">
                            <button type="button" wire:click="confirmReject" class="rounded-lg bg-red-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-red-700">{{ __('Reject order') }}</button>
                            <button type="button" wire:click="cancelReject" class="o-btn-ghost text-sm">{{ __('Cancel') }}</button>
                        </div>
                    @else
                        <div class="mt-3 flex flex-wrap justify-end gap-2">
                            <a href="https://wa.me/{{ $order->customer_phone }}" target="_blank" rel="noopener" class="o-btn-ghost text-sm">WhatsApp</a>
                            <button type="button" wire:click="startReject({{ $order->id }})" class="o-btn-ghost text-sm text-red-600">{{ __('Reject') }}</button>
                            <button type="button" wire:click="accept({{ $order->id }})" wire:loading.attr="disabled" class="o-btn-primary text-sm">{{ __('Accept') }}</button>
                        </div>
                    @endif
                @elseif ($order->status === 'rejected' && $order->reject_reason)
                    <p class="mt-2 text-xs text-red-600">{{ $order->reject_reason }}</p>
                @endif
            </div>
        @empty
            <div class="rounded-2xl bg-white p-8 text-center text-sm text-chrome-400 ring-1 ring-chrome-900/[0.06]">
                {{ __('No website orders here.') }}
            </div>
        @endforelse
    </div>

    <div class="mt-4">{{ $orders->links() }}</div>
</div>
