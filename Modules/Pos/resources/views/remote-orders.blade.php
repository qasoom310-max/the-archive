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
        ['key' => 'unpaid', 'label' => __('Unpaid'), 'count' => $unpaidCount],
        ['key' => 'new', 'label' => __('New'), 'count' => $counts['new'] ?? 0],
        ['key' => 'packed', 'label' => __('Packed'), 'count' => $counts['packed'] ?? 0],
        ['key' => 'out_for_delivery', 'label' => __('Out for delivery'), 'count' => $counts['out_for_delivery'] ?? 0],
        ['key' => 'delivered', 'label' => __('Delivered'), 'count' => $counts['delivered'] ?? 0],
        ['key' => 'all', 'label' => __('All'), 'count' => null],
    ];
    $payBadge = [
        'paid' => 'bg-emerald-50 text-emerald-700',
        'partial' => 'bg-amber-50 text-amber-700',
        'unpaid' => 'bg-red-50 text-red-600',
    ];
    $payLabel = ['paid' => __('Paid'), 'partial' => __('Part paid'), 'unpaid' => __('Unpaid')];
@endphp

<div class="mx-auto max-w-4xl p-4 sm:p-6">
    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold text-chrome-900">{{ __('Remote / delivery sales') }}</h1>
            <p class="text-sm text-chrome-500">{{ __('The fulfillment queue for phone, WhatsApp and delivery orders.') }}</p>
            {{-- Delivery money for the chosen period (default: this month), so
                 the figure answers "what is delivery costing me now" instead of
                 being an ever-growing all-time total. --}}
            <div class="mt-2 inline-flex flex-wrap items-center gap-1.5">
                <span class="text-[11px] font-semibold uppercase tracking-wide text-chrome-400">{{ __('Delivery') }}</span>
                @foreach ([['month', __('This month')], ['last', __('Last month')], ['all', __('All time')]] as [$key, $label])
                    <button type="button" wire:click="setPeriod('{{ $key }}')"
                        class="rounded-full px-2 py-0.5 text-[11px] font-medium transition
                            {{ $period === $key ? 'bg-primary-400 text-chrome-900' : 'bg-chrome-100 text-chrome-500 hover:bg-chrome-200' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </div>
            <p class="mt-1 text-xs font-medium text-red-500">{{ __('Delivery costs (our expense)') }}: {{ $money($deliveryCostTotal) }}</p>
            <p class="mt-0.5 text-xs font-medium text-emerald-600">{{ __('Delivery charged to customers') }}: {{ $money($deliveryChargeTotal) }}</p>
            <p class="mt-0.5 text-[11px] text-chrome-400">
                {{ __('Net delivery cost') }}: {{ $money($deliveryCostTotal - $deliveryChargeTotal) }} · {{ $periodLabel }}
            </p>
        </div>
        <div class="flex shrink-0 items-center gap-2">
            {{-- Where the collected money is tracked until it reaches the bank. --}}
            <a href="{{ url('/app/pos/settlements') }}" wire:navigate class="o-btn-ghost">{{ __('Delivery money') }}</a>
            @if ($canCreate)
                <a href="{{ $startUrl }}" wire:navigate class="o-btn-primary">
                    <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10 5a1 1 0 0 1 1 1v3h3a1 1 0 1 1 0 2h-3v3a1 1 0 1 1-2 0v-3H6a1 1 0 1 1 0-2h3V6a1 1 0 0 1 1-1Z"/></svg>
                    {{ __('New remote order') }}
                </a>
            @endif
        </div>
    </div>
    <p class="mb-4 -mt-2 text-xs text-chrome-400">{{ __('Orders are rung up at the register, then tracked and delivered here.') }}</p>

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

    @if (session('remote_status'))
        <div class="mb-4 rounded-lg bg-primary-50 px-4 py-2.5 text-sm font-medium text-chrome-800 ring-1 ring-primary-200">
            {{ session('remote_status') }}
        </div>
    @endif

    {{-- Bulk actions: a delivery round comes back with a dozen orders to
         collect or move on, and doing each singly is the same click a dozen
         times. Only shown once something is ticked. --}}
    @if ($canFulfill && count($selectedOrders) > 0)
        <div class="mb-3 flex flex-wrap items-center justify-between gap-3 rounded-xl bg-primary-50 p-3 ring-1 ring-primary-200">
            <p class="text-sm font-medium text-chrome-800">
                {{ trans_choice('{1}:count order selected|[2,*]:count orders selected', count($selectedOrders), ['count' => count($selectedOrders)]) }}
                @if ($selectedUncollected > 0)
                    · {{ __('to collect') }} <span class="font-bold">{{ $money($selectedUncollected) }}</span>
                @endif
            </p>
            <div class="flex flex-wrap items-center gap-2">
                @if ($selectedUncollected > 0)
                    <button type="button" wire:click="collectSelected"
                        wire:confirm="{{ __('Collect payment on the selected orders?') }}"
                        class="o-btn-primary">{{ __('Collect') }} {{ $money($selectedUncollected) }}</button>
                @endif
                <button type="button" wire:click="advanceSelected" class="o-btn-ghost">{{ __('Move forward') }}</button>
                <button type="button" wire:click="clearSelection" class="o-btn-ghost">{{ __('Clear') }}</button>
            </div>
        </div>
    @endif

    @if ($orders->isEmpty())
        <div class="rounded-2xl border border-dashed border-chrome-300 bg-white p-10 text-center">
            <p class="text-sm text-chrome-400">{{ __('No remote orders here yet.') }}</p>
        </div>
    @else
        @if ($canFulfill)
            <div class="mb-2 flex items-center gap-3 text-xs">
                <button type="button" wire:click="selectAllShown" class="font-medium text-primary-700 hover:underline">{{ __('Select all shown') }}</button>
                @if (count($selectedOrders) > 0)
                    <button type="button" wire:click="clearSelection" class="text-chrome-400 hover:text-chrome-700">{{ __('Clear') }}</button>
                @endif
            </div>
        @endif
        <div class="space-y-3">
            @foreach ($orders as $order)
                @php
                    $status = $order->fulfillment_status;
                    $next = $status?->next();
                    $pay = $order->paymentBadge();
                @endphp
                <div wire:key="remote-{{ $order->id }}"
                    class="rounded-2xl bg-white p-4 shadow-sm ring-1 sm:p-5 {{ in_array($order->id, $selectedOrders) ? 'ring-primary-400' : 'ring-chrome-900/[0.06]' }}">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                @if ($canFulfill)
                                    <input type="checkbox" wire:model.live="selectedOrders" value="{{ $order->id }}"
                                        aria-label="{{ __('Select :ref', ['ref' => $order->reference]) }}"
                                        class="size-4 shrink-0 rounded border-chrome-300 text-primary-600 focus:ring-primary-500">
                                @endif
                                <span class="text-sm font-semibold text-chrome-900">{{ $order->customer_name ?: __('Customer') }}</span>
                                @if ($status)
                                    <span class="rounded-full px-2 py-0.5 text-[11px] font-medium {{ $badge[$status->color()] ?? 'bg-chrome-100 text-chrome-600' }}">{{ $status->label() }}</span>
                                @endif
                                <span class="rounded-full px-2 py-0.5 text-[11px] font-medium {{ $payBadge[$pay] }}">{{ $payLabel[$pay] }}</span>
                            </div>
                            <div class="mt-0.5 text-xs text-chrome-500">
                                {{ $order->reference }}
                                @if ($order->customer_phone) · <span class="tabular-nums">{{ $order->customer_phone }}</span>@endif
                                @if ($order->ordered_at) · {{ $order->ordered_at->format('Y-m-d H:i') }}@endif
                            </div>
                            @if ($order->delivery_address)
                                <p class="mt-1 text-sm text-chrome-600">{{ $order->delivery_address }}</p>
                            @endif
                            @if ($canFulfill)
                                <div class="mt-1.5 flex items-center gap-2">
                                    <label class="text-xs text-chrome-400">{{ __('Delivery ref') }}</label>
                                    <input type="text" value="{{ $order->delivery_reference }}"
                                        @change="$wire.setDeliveryReference({{ $order->id }}, $event.target.value)"
                                        placeholder="{{ __('add') }}" class="o-input h-7 w-44 text-xs">
                                </div>
                            @elseif ($order->delivery_reference)
                                <p class="mt-1 text-xs text-chrome-500">{{ __('Delivery ref') }}: {{ $order->delivery_reference }}</p>
                            @endif
                        </div>
                        <div class="text-end">
                            <p class="text-base font-bold text-chrome-900">{{ $money($order->total) }}</p>
                            <p class="text-xs text-chrome-400">
                                {{ $order->lines_count }} {{ __('items') }}
                                @if ($order->delivery_fee > 0) · {{ __('Delivery cost') }} {{ $money($order->delivery_fee) }}@endif
                            </p>
                        </div>
                    </div>

                    <div class="mt-3 flex items-center justify-between gap-3 border-t border-chrome-100 pt-3">
                        <a href="{{ url('/app/pos/order/' . $order->id . '/receipt') }}" target="_blank"
                            class="text-xs font-medium text-chrome-500 hover:text-primary-700">{{ __('Receipt') }}</a>
                        <div class="flex items-center gap-2">
                            @if ($canFulfill && $pay !== 'paid')
                                <button type="button" wire:click="collectPayment({{ $order->id }})"
                                    class="rounded-lg border border-emerald-500 px-3 py-1.5 text-sm font-semibold text-emerald-700 hover:bg-emerald-50">
                                    {{ __('Collect') }} {{ $money($order->outstanding()) }}
                                </button>
                            @endif
                            @if ($canFulfill && $next !== null && $status !== null)
                                <button type="button" wire:click="advance({{ $order->id }})"
                                    class="o-btn-primary text-sm">{{ __($status->advanceLabel()) }}</button>
                            @elseif ($status === FulfillmentStatus::Delivered && $pay === 'paid')
                                <span class="text-xs font-medium text-emerald-600">✓ {{ __('Delivered') }}</span>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
