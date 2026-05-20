@php $money = fn ($v) => number_format((float) $v, 2); @endphp

<div class="flex h-[calc(100vh-3rem)] bg-chrome-100" wire:poll.30s="heartbeat">
    {{-- ───────────── Order / cart panel ───────────── --}}
    <section class="flex w-[38%] min-w-[340px] flex-col border-r border-chrome-200 bg-white">
        <div class="flex items-center justify-between border-b border-chrome-200 px-4 py-3">
            <div class="min-w-0">
                <p class="text-sm font-bold text-chrome-900">{{ $order->reference }}</p>
                <a href="{{ url('/app/pos/session/' . $sessionId) }}" wire:navigate
                    class="text-xs text-chrome-400 hover:text-primary-700">Session #{{ $sessionId }} · back</a>
            </div>
            <div class="flex items-center gap-3">
                @include('pos::partials.user-chip', ['user' => $cashier, 'sub' => 'Cashier'])
                <button wire:click="newOrder" class="o-btn-ghost text-xs">New order</button>
            </div>
        </div>

        {{-- Customer --}}
        <div class="border-b border-chrome-200 px-4 py-2">
            @if ($order->partner)
                <div class="flex items-center justify-between">
                    <span class="text-sm"><span class="text-chrome-400">Customer:</span>
                        <span class="font-medium">{{ $order->partner->name }}</span></span>
                    <button wire:click="clearCustomer" class="text-xs text-red-600 hover:underline">remove</button>
                </div>
            @else
                <div class="relative">
                    <input wire:model.live.debounce.300ms="customerSearch" placeholder="Search customer…"
                        class="o-input text-sm">
                    @if ($customerResults->isNotEmpty())
                        <div class="absolute z-20 mt-1 w-full rounded-lg border border-chrome-200 bg-white shadow-pop">
                            @foreach ($customerResults as $c)
                                <button wire:click="setCustomer({{ $c->id }})"
                                    class="block w-full px-3 py-2 text-left text-sm hover:bg-chrome-100">
                                    {{ $c->name }} <span class="text-chrome-400">{{ $c->email }}</span>
                                </button>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif
        </div>

        {{-- Lines --}}
        <div class="flex-1 overflow-y-auto">
            @forelse ($lines as $line)
                <div wire:key="line-{{ $line->id }}"
                    class="flex items-center gap-3 border-b border-chrome-100 px-4 py-2.5">
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium text-chrome-800">{{ $line->name }}</p>
                        <p class="text-xs text-chrome-400">
                            {{ $money($line->unit_price) }} each
                            @if ($line->tax_rate > 0) · tax {{ $money($line->tax_rate) }}% @endif
                        </p>
                    </div>

                    {{-- Quantity stepper: −  [qty]  +  (decrementing to 0 removes the line) --}}
                    <div class="flex shrink-0 items-center rounded-lg border border-chrome-200 bg-chrome-50">
                        <button type="button" wire:click="updateQuantity({{ $line->id }}, false)"
                            class="flex size-7 items-center justify-center rounded-l-lg text-chrome-600 hover:bg-chrome-200"
                            aria-label="Decrease quantity">−</button>
                        <span class="w-9 text-center text-sm font-semibold tabular-nums text-chrome-900">{{ (int) $line->qty }}</span>
                        <button type="button" wire:click="updateQuantity({{ $line->id }}, true)"
                            class="flex size-7 items-center justify-center rounded-r-lg text-chrome-600 hover:bg-chrome-200"
                            aria-label="Increase quantity">+</button>
                    </div>

                    <div class="w-20 shrink-0 text-right">
                        <p class="text-sm font-semibold text-chrome-900">{{ $money($line->total) }}</p>
                        <button type="button" wire:click="removeLine({{ $line->id }})"
                            class="text-xs text-red-500 hover:underline">remove</button>
                    </div>
                </div>
            @empty
                <p class="px-4 py-10 text-center text-sm text-chrome-400">Cart is empty — tap products to add.</p>
            @endforelse
        </div>

        {{-- Totals + pay --}}
        <div class="border-t border-chrome-200 px-4 py-3">
            <div class="flex justify-between text-sm text-chrome-500">
                <span>Subtotal</span><span>{{ $money($order->subtotal) }}</span>
            </div>
            <div class="flex justify-between text-sm text-chrome-500">
                <span>Tax</span><span>{{ $money($order->tax_total) }}</span>
            </div>
            <div class="mt-1 flex justify-between text-lg font-bold text-chrome-900">
                <span>Total</span><span>{{ $money($order->total) }}</span>
            </div>
            <button wire:click="startPayment"
                @disabled($lines->isEmpty())
                class="o-btn-primary mt-3 w-full justify-center py-2.5 text-base disabled:opacity-40">
                Payment · {{ $money($order->total) }}
            </button>
        </div>
    </section>

    {{-- ───────────── Product grid ───────────── --}}
    <section class="flex flex-1 flex-col">
        <div class="border-b border-chrome-200 bg-white px-4 py-3">
            <div class="flex flex-wrap items-center gap-2">
                <input wire:model.live.debounce.250ms="search" placeholder="Search product or scan barcode…"
                    class="o-input max-w-sm text-sm">
                <button wire:click="$set('categoryId', null)"
                    class="o-btn {{ $categoryId === null ? 'o-btn-primary' : 'o-btn-ghost' }}">All</button>
                @foreach ($categories as $cat)
                    <button wire:click="$set('categoryId', {{ $cat->id }})"
                        class="o-btn {{ $categoryId === $cat->id ? 'o-btn-primary' : 'o-btn-ghost' }}">
                        @if ($cat->image)<span class="mr-1">{{ $cat->image }}</span>@endif{{ $cat->name }}
                    </button>
                @endforeach
            </div>

            @if ($subCategories->isNotEmpty())
                <div class="mt-2 flex flex-wrap items-center gap-1.5 border-t border-chrome-100 pt-2">
                    <span class="text-xs text-chrome-400">Subcategories</span>
                    @foreach ($subCategories as $sub)
                        <button wire:click="$set('categoryId', {{ $sub->id }})"
                            class="o-btn o-btn-ghost text-xs {{ $categoryId === $sub->id ? 'ring-1 ring-primary-400' : '' }}">
                            @if ($sub->image)<span class="mr-1">{{ $sub->image }}</span>@endif{{ $sub->name }}
                        </button>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="grid flex-1 auto-rows-min grid-cols-2 gap-3 overflow-y-auto p-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
            @forelse ($products as $product)
                @php $yield = $product->theoreticalYield(); @endphp
                <button wire:click="addProduct({{ $product->id }})" wire:key="prod-{{ $product->id }}"
                    class="relative flex flex-col rounded-xl bg-white p-3 text-left shadow-sm ring-1 transition
                        {{ $yield !== null && $yield <= 0
                            ? 'ring-red-300 opacity-60 hover:ring-red-400'
                            : 'ring-chrome-900/5 hover:ring-primary-400' }}">
                    @if ($yield !== null && $yield <= 0)
                        <span class="absolute right-2 top-2 rounded-full bg-red-600 px-2 py-0.5 text-[10px] font-bold text-white">
                            Out of ingredients
                        </span>
                    @elseif ($yield !== null && $yield <= 5)
                        <span class="absolute right-2 top-2 rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-bold text-amber-700">
                            {{ $yield }} left
                        </span>
                    @endif
                    <span class="flex h-32 items-center justify-center overflow-hidden rounded-lg bg-chrome-100 text-4xl font-bold text-chrome-300">
                        @if ($product->image_path)
                            {{-- object-contain so portrait shots (e.g. a shisha) show fully,
                                 letterboxed on the chrome bg instead of being cropped. --}}
                            <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($product->image_path) }}"
                                alt="{{ $product->name }}" class="size-full object-contain">
                        @else
                            {{ \Illuminate\Support\Str::substr($product->name, 0, 1) }}
                        @endif
                    </span>
                    <span class="mt-2 line-clamp-2 text-sm font-medium text-chrome-800">{{ $product->name }}</span>
                    <span class="mt-auto pt-1 text-sm font-bold text-primary-700">{{ $money($product->price) }}</span>
                </button>
            @empty
                <p class="col-span-full py-10 text-center text-sm text-chrome-400">No products. Seed some or add via POS Products.</p>
            @endforelse
        </div>
    </section>

    {{-- ───────────── Payment overlay ───────────── --}}
    @if ($paying)
        <div class="fixed inset-0 z-40 flex items-center justify-center bg-chrome-900/40 p-4">
            <div class="w-full max-w-md rounded-2xl bg-white p-5 shadow-pop">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-base font-bold text-chrome-900">Payment</h2>
                    <button wire:click="$set('paying', false)" class="text-sm text-chrome-400 hover:text-chrome-700">✕</button>
                </div>

                @php $remaining = round($order->total - $order->paymentsTotal(), 2); @endphp
                <div class="rounded-xl bg-chrome-50 p-3 text-sm">
                    <div class="flex justify-between"><span class="text-chrome-500">Total</span><span class="font-semibold">{{ $money($order->total) }}</span></div>
                    <div class="flex justify-between"><span class="text-chrome-500">Paid</span><span>{{ $money($order->paymentsTotal()) }}</span></div>
                    <div class="flex justify-between text-base font-bold {{ $remaining <= 0 ? 'text-emerald-600' : 'text-chrome-900' }}">
                        <span>{{ $remaining > 0 ? 'Remaining' : 'Change' }}</span>
                        <span>{{ $money(abs($remaining)) }}</span>
                    </div>
                </div>

                <div class="mt-3 flex flex-wrap gap-2">
                    @foreach ($paymentMethods as $pm)
                        <button wire:click="$set('paymentMethodId', {{ $pm->id }})"
                            class="o-btn {{ $paymentMethodId === $pm->id ? 'o-btn-primary' : 'o-btn-ghost' }}">{{ $pm->name }}</button>
                    @endforeach
                </div>

                <div class="mt-3 flex gap-2">
                    <input type="number" step="0.01" wire:model="tendered" class="o-input" placeholder="Amount tendered">
                    <button wire:click="addPayment" class="o-btn-primary shrink-0">Add</button>
                </div>

                @if ($order->payments->isNotEmpty())
                    <ul class="mt-3 space-y-1 text-xs text-chrome-500">
                        @foreach ($order->payments as $p)
                            <li class="flex justify-between"><span>{{ $p->method?->name }}</span><span>{{ $money($p->amount) }}</span></li>
                        @endforeach
                    </ul>
                @endif

                <button wire:click="validateOrder"
                    @disabled(! $order->isFullyPaid())
                    class="o-btn-primary mt-4 w-full justify-center py-2.5 text-base disabled:opacity-40">
                    Validate
                </button>
            </div>
        </div>
    @endif

    {{-- ───────────── Receipt overlay ───────────── --}}
    @if ($receipt)
        <div class="fixed inset-0 z-40 flex items-center justify-center bg-chrome-900/40 p-4">
            <div class="w-full max-w-sm rounded-2xl bg-white p-5 shadow-pop">
                <div class="text-center">
                    <p class="text-base font-bold text-chrome-900">OpenERP POS</p>
                    <p class="text-xs text-chrome-400">{{ $receipt->reference }} · {{ $now->isoFormat('MMM D, YYYY HH:mm') }}</p>
                    @if ($receipt->partner)<p class="text-xs text-chrome-500">Customer: {{ $receipt->partner->name }}</p>@endif
                </div>
                <div class="my-3 border-y border-dashed border-chrome-300 py-2 text-sm">
                    @foreach ($receipt->lines as $l)
                        <div class="flex justify-between">
                            <span>{{ rtrim(rtrim(number_format($l->qty, 3), '0'), '.') }}× {{ $l->name }}</span>
                            <span>{{ $money($l->total) }}</span>
                        </div>
                    @endforeach
                </div>
                <div class="space-y-0.5 text-sm">
                    <div class="flex justify-between text-chrome-500"><span>Subtotal</span><span>{{ $money($receipt->subtotal) }}</span></div>
                    <div class="flex justify-between text-chrome-500"><span>Tax</span><span>{{ $money($receipt->tax_total) }}</span></div>
                    <div class="flex justify-between font-bold"><span>Total</span><span>{{ $money($receipt->total) }}</span></div>
                    @foreach ($receipt->payments as $p)
                        <div class="flex justify-between text-chrome-500"><span>{{ $p->method?->name }}</span><span>{{ $money($p->amount) }}</span></div>
                    @endforeach
                    <div class="flex justify-between font-semibold text-emerald-600"><span>Change</span><span>{{ $money($receipt->change_due) }}</span></div>
                </div>
                <p class="mt-3 text-center text-xs text-chrome-400">Thank you!</p>
                <div class="mt-4 flex gap-2">
                    <button onclick="window.print()" class="o-btn-ghost flex-1 justify-center">Print</button>
                    <button wire:click="newOrder" class="o-btn-primary flex-1 justify-center">New order</button>
                </div>
            </div>
        </div>
    @endif
</div>
