@php $money = fn ($v) => \App\Erp\Money\Currencies::format($v); @endphp

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

        {{-- Customer — either shows the attached partner + remove button, or
             a "+ Customer" button that opens the Odoo-19-style picker modal
             (list of existing partners + search + Create button). --}}
        <div class="border-b border-chrome-200 px-4 py-2">
            @if ($order->partner)
                <div class="flex items-center justify-between">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium text-chrome-800">{{ $order->partner->name }}</p>
                        <p class="truncate text-xs text-chrome-400">
                            {{ $order->partner->phone }}@if ($order->partner->phone && $order->partner->email) · @endif{{ $order->partner->email }}
                        </p>
                    </div>
                    <button wire:click="clearCustomer" class="ml-2 shrink-0 text-xs text-red-600 hover:underline">remove</button>
                </div>
            @else
                {{-- Primary-purple, sized to ~1/3 of the cart panel width. --}}
                <button type="button" wire:click="openCustomerPicker"
                    class="o-btn-primary w-1/3 justify-center gap-1.5 text-sm">
                    <span class="text-base leading-none">+</span> Customer
                </button>
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
                            @if ($line->tax_rate > 0) · tax {{ number_format((float) $line->tax_rate, 2) }}% @endif
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

    {{-- ───────────── Choose-customer picker overlay (Odoo-19 style) ───────────── --}}
    @if ($pickingCustomer)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-chrome-900/40 p-4">
            <div class="flex max-h-[80vh] w-full max-w-3xl flex-col overflow-hidden rounded-2xl bg-white shadow-pop">
                {{-- Header: Create button (purple, primary action) + title on the left,
                     live search on the right. Search filters by name/phone/email. --}}
                <div class="flex items-center justify-between gap-4 border-b border-chrome-200 px-5 py-3">
                    <div class="flex items-center gap-3">
                        <button type="button" wire:click="startCreateCustomer"
                            class="o-btn-primary text-sm">Create</button>
                        <h2 class="text-base font-semibold text-chrome-900">Choose Customer</h2>
                    </div>
                    <input wire:model.live.debounce.250ms="customerSearch"
                        placeholder="Search Customers…"
                        class="o-input w-72 max-w-full text-sm" autocomplete="off">
                </div>

                {{-- List — flex row with two sibling buttons (pick + delete) per
                     row. Sibling rather than nested because <button> inside <button>
                     is invalid HTML and gives unpredictable click behaviour. The
                     trash button only renders when the user has Unlink on
                     contacts.partner (cashiers without delete rights don't see it). --}}
                <div class="flex-1 divide-y divide-chrome-100 overflow-y-auto">
                    @forelse ($customerList as $c)
                        <div class="group flex items-stretch hover:bg-chrome-50" wire:key="cust-{{ $c->id }}">
                            <button type="button" wire:click="pickCustomer({{ $c->id }})"
                                class="flex flex-1 items-start justify-between gap-4 px-5 py-3 text-left">
                                <div class="min-w-0 flex-1">
                                    <p class="truncate font-semibold text-chrome-900">{{ $c->name }}</p>
                                    @if ($c->city || $c->country)
                                        <p class="truncate text-xs text-chrome-500">
                                            {{ trim(($c->city ?? '') . ' ' . ($c->country ?? '')) }}
                                        </p>
                                    @endif
                                </div>
                                <div class="shrink-0 text-right text-sm">
                                    @if ($c->phone)
                                        <p class="font-medium text-chrome-700 tabular-nums">{{ $c->phone }}</p>
                                    @endif
                                    @if ($c->email)
                                        <p class="text-chrome-500">{{ $c->email }}</p>
                                    @endif
                                </div>
                            </button>
                            @if ($canEditCustomers || $canDeleteCustomers)
                                <div class="flex shrink-0 items-stretch gap-1 pr-3">
                                    @if ($canEditCustomers)
                                        <button type="button"
                                            wire:click="openEditCustomer({{ $c->id }})"
                                            title="Edit customer"
                                            aria-label="Edit {{ $c->name }}"
                                            class="flex w-8 items-center justify-center text-blue-500 transition-colors hover:text-blue-700">
                                            {{-- Heroicons mini pencil-square --}}
                                            <svg class="size-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125" />
                                            </svg>
                                        </button>
                                    @endif
                                    @if ($canDeleteCustomers)
                                        <button type="button"
                                            wire:click="deleteCustomer({{ $c->id }})"
                                            wire:confirm="Delete {{ $c->name }} forever? This cannot be undone — past orders for this customer will be kept but unlinked."
                                            title="Delete customer"
                                            aria-label="Delete {{ $c->name }}"
                                            class="flex w-8 items-center justify-center text-red-500 transition-colors hover:text-red-700">
                                            {{-- Heroicons mini trash --}}
                                            <svg class="size-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21q.149.222.22.469M19.228 5.79a48.108 48.108 0 0 0-3.478-.397m-12 .562q.249-.247.561-.398a48 48 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0M5.75 5.79l.875 13.114a2.25 2.25 0 0 0 2.244 2.077h6.262a2.25 2.25 0 0 0 2.244-2.077L18.25 5.79" />
                                            </svg>
                                        </button>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @empty
                        <p class="px-5 py-10 text-center text-sm text-chrome-400">
                            @if ($customerSearch !== '')
                                No customers match "<span class="font-medium text-chrome-600">{{ $customerSearch }}</span>" — click Create to add one.
                            @else
                                No customers yet. Click Create to add the first one.
                            @endif
                        </p>
                    @endforelse
                </div>

                {{-- Footer: discard closes without selecting. --}}
                <button type="button" wire:click="closeCustomerPicker"
                    class="border-t border-chrome-200 px-5 py-3 text-center text-sm font-medium text-chrome-600 hover:bg-chrome-50">
                    Discard
                </button>
            </div>
        </div>
    @endif

    {{-- ───────────── Add-customer overlay ───────────── --}}
    @if ($addingCustomer)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-chrome-900/40 p-4">
            <div class="w-full max-w-md rounded-2xl bg-white p-5 shadow-pop">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="text-base font-bold text-chrome-900">
                        {{ $editingCustomerId !== null ? 'Edit customer' : 'Add customer' }}
                    </h2>
                    <button type="button" wire:click="cancelAddCustomer"
                        class="text-sm text-chrome-400 hover:text-chrome-700">✕</button>
                </div>

                {{-- Name (required) --}}
                <div class="mb-3">
                    <label class="block text-xs font-semibold uppercase tracking-wide text-chrome-500">
                        Name <span class="text-red-500">*</span>
                    </label>
                    <input wire:model="newCustomerName"
                        class="o-input mt-1 text-sm" placeholder="Customer name" autocomplete="off">
                    @error('newCustomerName')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Phone (required) — same dial-code dropdown as the receipt
                     row, so the digits Partner stores match what the receipt
                     listener will send to. --}}
                <div class="mb-3">
                    <label class="block text-xs font-semibold uppercase tracking-wide text-chrome-500">
                        Phone <span class="text-red-500">*</span>
                    </label>
                    <div class="mt-1 flex gap-2">
                        @include('pos::partials.country-picker', [
                            'wireModel' => 'newCustomerCountryCode',
                            'countries' => $whatsappCountries,
                        ])
                        <input type="tel" inputmode="numeric" wire:model="newCustomerPhone"
                            class="o-input flex-1 text-sm tabular-nums" placeholder="Phone number" autocomplete="off">
                    </div>
                    @error('newCustomerPhone')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                    @error('newCustomerCountryCode')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Email (optional) --}}
                <div class="mb-5">
                    <label class="block text-xs font-semibold uppercase tracking-wide text-chrome-500">
                        Email <span class="ml-1 font-normal normal-case tracking-normal text-chrome-400">(optional)</span>
                    </label>
                    <input type="email" wire:model="newCustomerEmail"
                        class="o-input mt-1 text-sm" placeholder="customer@example.com" autocomplete="off">
                    @error('newCustomerEmail')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex gap-2">
                    <button type="button" wire:click="cancelAddCustomer"
                        class="o-btn-ghost flex-1 justify-center">Cancel</button>
                    <button type="button" wire:click="saveCustomer"
                        class="o-btn-primary flex-1 justify-center">
                        {{ $editingCustomerId !== null ? 'Save changes' : 'Save customer' }}
                    </button>
                </div>
            </div>
        </div>
    @endif

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

                {{-- WhatsApp receipt phone — narrow country dropdown (with full
                     "Bahrain (+973)" label) + wide local-number input. The select uses
                     explicit utilities instead of `.o-input` because `o-input`'s baked-in
                     `w-full` overrides `w-28` and stretches the dropdown across the row,
                     squeezing the phone input to 0 width. Direct utilities sidestep that
                     conflict. Blank local = walk-in, no message sent. --}}
                <div class="mt-3">
                    <label class="block text-xs font-semibold uppercase tracking-wide text-chrome-500">
                        WhatsApp receipt
                        <span class="ml-1 font-normal normal-case tracking-normal text-chrome-400">(optional)</span>
                    </label>
                    <div class="mt-1 flex gap-2">
                        @include('pos::partials.country-picker', [
                            'wireModel' => 'countryCode',
                            'countries' => $whatsappCountries,
                        ])
                        <input type="tel" inputmode="numeric" wire:model="localPhone"
                            class="o-input flex-1 text-sm tabular-nums" placeholder="Phone number" autocomplete="off">
                    </div>
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
                    {{-- Custom company logo when uploaded; falls back to
                         the company name text + "OpenERP POS". The logo
                         is the main reason this setting exists, so it
                         displays prominently on the receipt header. --}}
                    @php
                        $receiptLogoUrl = \App\Erp\Branding\Logo::url();
                        $companyName = \App\Erp\Settings\Setting::get('company.name', 'OpenERP');
                    @endphp
                    @if ($receiptLogoUrl)
                        <img src="{{ $receiptLogoUrl }}" alt="{{ $companyName }}"
                            class="mx-auto mb-2 h-14 w-auto max-w-[10rem] object-contain">
                    @endif
                    <p class="text-base font-bold text-chrome-900">{{ $companyName }}</p>
                    {{-- 12-hour clock (`h:mm A`) so receipts read "6:27 PM"
                         instead of "18:27" — matches the format every retail
                         POS in Bahrain / Saudi prints. `isoFormat` from Carbon
                         (Moment.js tokens) — `h` is hour 1–12, `A` is AM/PM. --}}
                    <p class="text-xs text-chrome-400">{{ $receipt->reference }} · {{ $now->isoFormat('MMM D, YYYY h:mm A') }}</p>
                    @if ($receipt->partner)<p class="text-xs text-chrome-500">Customer: {{ $receipt->partner->name }}</p>@endif
                    {{-- Stored as digits-only ("97333123456"); prefix "+" so
                         it reads as an international number. Falls through
                         silently when the cashier didn't capture one — most
                         walk-ins. --}}
                    @if ($receipt->customer_phone)<p class="text-xs text-chrome-500">Phone: +{{ $receipt->customer_phone }}</p>@endif
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
