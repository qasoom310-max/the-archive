@php
    use Modules\Pos\Enums\FulfillmentStatus;
    $money = fn ($v) => \App\Erp\Money\Currencies::format($v);
    $badge = [
        'amber' => 'bg-amber-50 text-amber-700',
        'sky' => 'bg-sky-50 text-sky-700',
        'indigo' => 'bg-indigo-50 text-indigo-700',
        'emerald' => 'bg-emerald-50 text-emerald-700',
    ];
    $tabs = [
        ['key' => 'active', 'label' => __('To fulfill'), 'count' => $activeCount],
        ['key' => 'new', 'label' => __('New'), 'count' => $counts['new'] ?? 0],
        ['key' => 'packed', 'label' => __('Packed'), 'count' => $counts['packed'] ?? 0],
        ['key' => 'out_for_delivery', 'label' => __('Out for delivery'), 'count' => $counts['out_for_delivery'] ?? 0],
        ['key' => 'delivered', 'label' => __('Delivered'), 'count' => $counts['delivered'] ?? 0],
        ['key' => 'all', 'label' => __('All'), 'count' => null],
    ];
@endphp

<div class="mx-auto max-w-4xl p-4 sm:p-6">
    <div class="mb-4">
        <h1 class="text-xl font-bold text-chrome-900">{{ __('Remote / delivery sales') }}</h1>
        <p class="text-sm text-chrome-500">{{ __('The fulfillment queue for phone, WhatsApp and delivery orders.') }}</p>
    </div>

    {{-- Status filter tabs --}}
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

    @if ($orders->isEmpty())
        <div class="rounded-2xl border border-dashed border-chrome-300 bg-white p-10 text-center">
            <p class="text-sm text-chrome-400">{{ __('No remote orders here yet.') }}</p>
        </div>
    @else
        <div class="space-y-3">
            @foreach ($orders as $order)
                @php
                    $status = $order->fulfillment_status;
                    $next = $status?->next();
                @endphp
                <div wire:key="remote-{{ $order->id }}" class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/[0.06] sm:p-5">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-semibold text-chrome-900">{{ $order->customer_name ?: __('Customer') }}</span>
                                @if ($status)
                                    <span class="rounded-full px-2 py-0.5 text-[11px] font-medium {{ $badge[$status->color()] ?? 'bg-chrome-100 text-chrome-600' }}">{{ $status->label() }}</span>
                                @endif
                            </div>
                            <div class="mt-0.5 text-xs text-chrome-500">
                                {{ $order->reference }}
                                @if ($order->customer_phone) · <span class="tabular-nums">{{ $order->customer_phone }}</span>@endif
                                @if ($order->ordered_at) · {{ $order->ordered_at->format('Y-m-d H:i') }}@endif
                            </div>
                            @if ($order->delivery_address)
                                <p class="mt-1 text-sm text-chrome-600">{{ $order->delivery_address }}</p>
                            @endif
                        </div>
                        <div class="text-end">
                            <p class="text-base font-bold text-chrome-900">{{ $money($order->total) }}</p>
                            <p class="text-xs text-chrome-400">
                                {{ $order->lines_count }} {{ __('items') }}
                                @if ($order->delivery_fee > 0) · {{ __('Delivery') }} {{ $money($order->delivery_fee) }}@endif
                            </p>
                        </div>
                    </div>

                    <div class="mt-3 flex items-center justify-between gap-3 border-t border-chrome-100 pt-3">
                        <a href="{{ url('/app/pos/order/' . $order->id . '/receipt') }}" target="_blank"
                            class="text-xs font-medium text-chrome-500 hover:text-primary-700">{{ __('Receipt') }}</a>
                        @if ($canFulfill && $next !== null && $status !== null)
                            <button type="button" wire:click="advance({{ $order->id }})"
                                class="o-btn-primary text-sm">{{ __($status->advanceLabel()) }}</button>
                        @elseif ($status === FulfillmentStatus::Delivered)
                            <span class="text-xs font-medium text-emerald-600">✓ {{ __('Delivered') }}</span>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
