@php $money = fn ($v) => \App\Erp\Money\Currencies::format($v); @endphp

<div class="flex h-[calc(100vh-3rem)] flex-col bg-chrome-100 lg:flex-row" wire:poll.30s="heartbeat">
    {{-- ───────────── Order / cart panel ─────────────
         Mobile (`<lg`): stacked above the product grid, capped at 45vh so
         the keyboard + product grid both stay reachable. Desktop (`lg+`):
         a fixed-width column on the start edge, full viewport height.
         `border-e` is the logical equivalent of border-r so the divider
         flips to the correct side under RTL Arabic. --}}
    <section class="flex max-h-[45vh] w-full shrink-0 flex-col border-b border-chrome-200 bg-white lg:max-h-none lg:w-[38%] lg:min-w-[340px] lg:border-b-0 lg:border-e">
        <div class="flex items-center justify-between border-b border-chrome-200 px-4 py-3">
            <div class="min-w-0">
                @if ($table)
                    <p class="truncate text-sm font-bold text-chrome-900">
                        {{ __('Table') }} {{ $table->name }}
                        <span class="ms-1 text-xs font-normal text-chrome-400">· {{ $table->floor?->name }}</span>
                    </p>
                    <a href="{{ url('/app/pos/session/' . $sessionId . '/floor') }}" wire:navigate
                        class="text-xs text-chrome-400 hover:text-primary-700">&larr; {{ __('Floor') }} · {{ $order->reference }}</a>
                @else
                    <p class="text-sm font-bold text-chrome-900">{{ $order->reference }}</p>
                    <a href="{{ url('/app/pos/session/' . $sessionId) }}" wire:navigate
                        class="text-xs text-chrome-400 hover:text-primary-700">{{ __('Session') }} #{{ $sessionId }} · {{ __('back') }}</a>
                @endif
            </div>
            <div class="flex items-center gap-3">
                @if ($table)
                    {{-- Guest count — the floor-plan "guests/seats" numerator. --}}
                    <div class="flex items-center gap-1">
                        <button type="button" wire:click="setGuests(-1)" aria-label="{{ __('Fewer guests') }}"
                            class="flex size-6 items-center justify-center rounded-md bg-chrome-100 text-base leading-none text-chrome-700 hover:bg-chrome-200">&minus;</button>
                        <span class="min-w-[3.25rem] text-center text-xs font-medium tabular-nums text-chrome-600">
                            <svg class="-mt-0.5 me-0.5 inline size-3.5 text-chrome-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 9a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm-7 8a7 7 0 0 1 14 0H3Z"/></svg>
                            {{ (int) $order->guest_count }}/{{ $table->seats }}
                        </span>
                        <button type="button" wire:click="setGuests(1)" aria-label="{{ __('More guests') }}"
                            class="flex size-6 items-center justify-center rounded-md bg-chrome-100 text-base leading-none text-chrome-700 hover:bg-chrome-200">+</button>
                    </div>
                @endif
                @include('pos::partials.user-chip', ['user' => $cashier, 'sub' => __('Cashier')])
                @if ((int) $lines->sum('qty') >= 2)
                    <button wire:click="openSplit" class="o-btn-ghost text-xs" title="{{ __('Split this order') }}">{{ __('Split') }}</button>
                @endif
                <button wire:click="newOrder" class="o-btn-ghost text-xs">{{ __('New order') }}</button>
            </div>
        </div>

        {{-- Customer discount — the cashier types a phone number and a
             matching per-phone discount applies. Nothing is saved as a
             customer; the same number is reused for the WhatsApp receipt at
             checkout. Shows the entered phone + applied discount, or a
             "+ Customer discount" button that opens the phone-entry modal. --}}
        <div class="border-b border-chrome-200 px-4 py-2">
            @if ($localPhone !== '')
                <div class="flex items-center justify-between">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium text-chrome-800 tabular-nums">{{ $countryCode }} {{ $localPhone }}</p>
                        @if ($order->customer_discount_percent > 0)
                            <p class="truncate text-xs font-medium text-emerald-600">
                                {{ rtrim(rtrim(number_format($order->customer_discount_percent, 2), '0'), '.') }}% {{ __('discount applied') }}
                            </p>
                        @else
                            <p class="truncate text-xs text-chrome-400">{{ __('No discount for this number') }}</p>
                        @endif
                    </div>
                    <div class="ms-2 flex shrink-0 items-center gap-2">
                        <button type="button" wire:click="openPhoneEntry" class="text-xs text-primary-600 hover:underline">{{ __('edit') }}</button>
                        <button type="button" wire:click="clearPhone" class="text-xs text-red-600 hover:underline">{{ __('remove') }}</button>
                    </div>
                </div>
            @else
                {{-- Primary brand button. Opens a phone-entry box; a number with an
                     admin-set discount applies it to the order automatically. --}}
                <button type="button" wire:click="openPhoneEntry"
                    class="o-btn-primary justify-center gap-1.5 text-sm">
                    <span class="text-base leading-none">+</span> {{ __('Customer discount') }}
                </button>
            @endif
        </div>

        {{-- Lines --}}
        <div class="flex-1 overflow-y-auto">
            @forelse ($lines as $line)
                {{-- Each cart row is its own Alpine island so the "note"
                     editor can collapse/expand without re-renders. The
                     note travels to the matching KDS ticket so the
                     cashier can flag "no pickle / extra spicy" without
                     leaving the terminal. --}}
                <div wire:key="line-{{ $line->id }}" x-data="{ noteOpen: {{ $line->notes ? 'true' : 'false' }} }"
                    class="border-b border-chrome-100 px-4 py-2.5">
                    <div class="flex items-center gap-3">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-chrome-800">{{ $line->name }}</p>
                            <p class="text-xs text-chrome-400">
                                {{ $money($line->unit_price) }} {{ __('each') }}
                                @if ($line->tax_rate > 0) · {{ __('tax') }} {{ number_format((float) $line->tax_rate, 2) }}% @endif
                            </p>
                            {{-- Attached condiments — name + surcharge (free ones show
                                 no price). Travels to the kitchen ticket + receipt. --}}
                            @if (!empty($line->condiments))
                                <div class="mt-1 flex flex-wrap gap-1">
                                    @foreach ($line->condiments as $c)
                                        <span class="inline-flex items-center gap-1 rounded bg-primary-50 px-1.5 py-0.5 text-[11px] font-medium text-primary-700">
                                            {{ $c['name'] }}@if (($c['price'] ?? 0) > 0)<span class="text-primary-500"> +{{ $money($c['price']) }}</span>@endif
                                        </span>
                                    @endforeach
                                </div>
                            @endif
                        </div>

                        {{-- Quantity stepper: −  [qty]  +  (decrementing to 0 removes the line) --}}
                        <div class="flex shrink-0 items-center rounded-lg border border-chrome-200 bg-chrome-50">
                            <button type="button" wire:click="updateQuantity({{ $line->id }}, false)"
                                class="flex size-7 items-center justify-center rounded-s-lg text-chrome-600 hover:bg-chrome-200"
                                aria-label="{{ __('Decrease quantity') }}">−</button>
                            <span class="w-9 text-center text-sm font-semibold tabular-nums text-chrome-900">{{ (int) $line->qty }}</span>
                            <button type="button" wire:click="updateQuantity({{ $line->id }}, true)"
                                class="flex size-7 items-center justify-center rounded-e-lg text-chrome-600 hover:bg-chrome-200"
                                aria-label="{{ __('Increase quantity') }}">+</button>
                        </div>

                        <div class="w-28 shrink-0 text-end">
                            <p class="text-sm font-semibold text-chrome-900">{{ $money($line->total) }}</p>
                            <div class="flex flex-wrap items-center justify-end gap-x-2 gap-y-0.5">
                                <button type="button" wire:click="openCondiments({{ $line->id }})"
                                    class="text-xs {{ !empty($line->condiments) ? 'font-semibold text-primary-600' : 'text-chrome-400 hover:text-primary-600' }}"
                                    title="{{ __('Add-ons') }}">{{ __('add-ons') }}</button>
                                <button type="button" @click="noteOpen = !noteOpen"
                                    :class="noteOpen || @js((bool) $line->notes) ? 'text-amber-600' : 'text-chrome-400 hover:text-amber-600'"
                                    class="text-xs"
                                    title="{{ __('Kitchen note') }}">{{ __('note') }}</button>
                                <button type="button" wire:click="removeLine({{ $line->id }})"
                                    class="text-xs text-red-500 hover:underline">{{ __('remove') }}</button>
                            </div>
                        </div>
                    </div>

                    {{-- Inline note editor. Saves on blur (and on Enter) so
                         the cashier doesn't lose half-typed text on a swipe.
                         Empty value clears the note. --}}
                    <div x-show="noteOpen" x-cloak class="mt-2">
                        <input type="text"
                               value="{{ $line->notes }}"
                               placeholder="{{ __('e.g. no pickle, extra chilli…') }}"
                               @blur="$wire.setLineNotes({{ $line->id }}, $event.target.value)"
                               @keydown.enter.prevent="$wire.setLineNotes({{ $line->id }}, $event.target.value); noteOpen = false"
                               class="w-full rounded-md border border-amber-300 bg-amber-50 px-3 py-1.5 text-sm text-amber-900 placeholder:text-amber-400 focus:border-amber-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-amber-500/30">
                    </div>
                </div>
            @empty
                <p class="px-4 py-10 text-center text-sm text-chrome-400">{{ __('Cart is empty — tap products to add.') }}</p>
            @endforelse
        </div>

        {{-- Totals + pay --}}
        <div class="border-t border-chrome-200 px-4 py-3">
            <div class="flex justify-between text-sm text-chrome-500">
                <span>{{ __('Subtotal') }}</span><span>{{ $money($order->subtotal) }}</span>
            </div>
            <div class="flex justify-between text-sm text-chrome-500">
                <span>{{ __('Tax') }}</span><span>{{ $money($order->tax_total) }}</span>
            </div>
            @if ($order->customer_discount_total > 0)
                <div class="flex justify-between text-sm font-medium text-emerald-600">
                    <span>{{ __('Customer discount') }} ({{ rtrim(rtrim(number_format($order->customer_discount_percent, 2), '0'), '.') }}%)</span>
                    <span>−{{ $money($order->customer_discount_total) }}</span>
                </div>
            @endif
            <div class="mt-1 flex justify-between text-lg font-bold text-chrome-900">
                <span>{{ __('Total') }}</span><span>{{ $money($order->total) }}</span>
            </div>
            <button wire:click="startPayment"
                @disabled($lines->isEmpty())
                class="o-btn-primary mt-3 w-full justify-center py-2.5 text-base disabled:opacity-40">
                {{ __('Payment') }} · {{ $money($order->total) }}
            </button>
        </div>
    </section>

    {{-- ───────────── Product grid ───────────── --}}
    <section class="flex flex-1 flex-col">
        <div class="border-b border-chrome-200 bg-white px-4 py-3">
            <div class="flex flex-wrap items-center gap-2">
                {{-- Search + camera-scan. The scan icon opens a ZXing camera
                     overlay; each decoded barcode calls scanBarcode() which
                     adds the product. Physical USB scanners still work by
                     typing straight into this input. --}}
                <div x-data="barcodeScanner($wire)" class="relative w-full max-w-sm">
                    <input wire:model.live.debounce.250ms="search" placeholder="{{ __('Search product or scan barcode…') }}"
                        class="o-input w-full pe-10 text-sm">
                    <button type="button" @click="openScanner()" title="{{ __('Scan with camera') }}"
                        aria-label="{{ __('Scan with camera') }}"
                        class="absolute inset-y-0 end-1.5 my-auto flex size-7 items-center justify-center rounded-md text-chrome-400 transition hover:bg-chrome-100 hover:text-primary-600">
                        {{-- Viewfinder frame + barcode bars = "scan a barcode". --}}
                        <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M4 7V5a1 1 0 0 1 1-1h2M17 4h2a1 1 0 0 1 1 1v2M20 17v2a1 1 0 0 1-1 1h-2M7 20H5a1 1 0 0 1-1-1v-2"/>
                            <path d="M8 8.5v7M11 8.5v7M14 8.5v7M16.5 8.5v7"/>
                        </svg>
                    </button>

                    {{-- Camera scanner overlay. wire:ignore so a Livewire
                         re-render (cart updating as items are scanned) never
                         tears down the live <video> stream. --}}
                    <div wire:ignore x-show="open" x-cloak
                        x-on:scan-hit.window="onHit($event.detail.name)"
                        x-on:scan-miss.window="onMiss($event.detail.barcode)"
                        @keydown.escape.window="close()"
                        class="fixed inset-0 z-[60] flex items-center justify-center bg-chrome-900/70 p-4">
                        <div class="w-full max-w-md overflow-hidden rounded-2xl bg-white shadow-pop" @click.outside="close()">
                            <div class="flex items-center justify-between border-b border-chrome-200 px-4 py-3">
                                <h2 class="text-base font-semibold text-chrome-900">{{ __('Scan barcode') }}</h2>
                                <button type="button" @click="close()" class="o-btn-primary text-sm">{{ __('Done') }}</button>
                            </div>

                            <div class="relative aspect-[4/3] bg-black">
                                <video x-ref="video" class="size-full object-cover" muted autoplay playsinline></video>
                                {{-- Aiming reticle; the big spread shadow dims everything outside it. --}}
                                <div x-show="error === ''" class="pointer-events-none absolute inset-0 flex items-center justify-center">
                                    <div class="h-24 w-3/4 rounded-lg border-2 border-primary-400/90 shadow-[0_0_0_9999px_rgba(0,0,0,0.35)]"></div>
                                </div>
                                <div x-show="starting" x-cloak class="absolute inset-0 flex items-center justify-center text-sm text-white/90">
                                    {{ __('Starting camera…') }}
                                </div>
                            </div>

                            <div class="px-4 py-3 text-sm">
                                <p x-show="error === ''" class="text-chrome-500">{{ __('Point the camera at a product barcode.') }}</p>
                                <p x-show="error === 'perm'" x-cloak class="text-red-600">{{ __('Camera permission denied. Allow camera access in your browser, then reopen.') }}</p>
                                <p x-show="error === 'nocam'" x-cloak class="text-red-600">{{ __('No camera found on this device.') }}</p>
                                <p x-show="error === 'other'" x-cloak class="text-red-600"><span x-text="errorDetail"></span></p>

                                <p x-show="lastMsg !== ''" x-cloak class="mt-1.5 font-medium" :class="lastOk ? 'text-emerald-600' : 'text-red-600'">
                                    <span x-show="lastOk">{{ __('Added') }}: <span x-text="lastMsg"></span></span>
                                    <span x-show="!lastOk" x-cloak>{{ __('No product for barcode') }} <span class="tabular-nums" x-text="lastMsg"></span></span>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <button wire:click="$set('categoryId', null)"
                    class="o-btn {{ $categoryId === null ? 'o-btn-primary' : 'o-btn-ghost' }}">{{ __('All') }}</button>
                @foreach ($categories as $cat)
                    <button wire:click="$set('categoryId', {{ $cat->id }})"
                        class="o-btn {{ $categoryId === $cat->id ? 'o-btn-primary' : 'o-btn-ghost' }}">
                        @if ($cat->image)<span class="me-1">{{ $cat->image }}</span>@endif{{ $cat->name }}
                    </button>
                @endforeach
            </div>

            @if ($subCategories->isNotEmpty())
                <div class="mt-2 flex flex-wrap items-center gap-1.5 border-t border-chrome-100 pt-2">
                    <span class="text-xs text-chrome-400">{{ __('Subcategories') }}</span>
                    @foreach ($subCategories as $sub)
                        <button wire:click="$set('categoryId', {{ $sub->id }})"
                            class="o-btn o-btn-ghost text-xs {{ $categoryId === $sub->id ? 'ring-1 ring-primary-400' : '' }}">
                            @if ($sub->image)<span class="me-1">{{ $sub->image }}</span>@endif{{ $sub->name }}
                        </button>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="grid flex-1 auto-rows-min grid-cols-2 gap-2 overflow-y-auto p-3 sm:gap-3 sm:grid-cols-3 sm:p-4 lg:grid-cols-4 xl:grid-cols-5">
            @forelse ($products as $product)
                @php $yield = $product->theoreticalYield(); @endphp
                <button wire:click="addProduct({{ $product->id }})" wire:key="prod-{{ $product->id }}"
                    class="relative flex flex-col rounded-xl bg-white p-3 text-start shadow-sm ring-1 transition
                        {{ $yield !== null && $yield <= 0
                            ? 'ring-red-300 opacity-60 hover:ring-red-400'
                            : 'ring-chrome-900/5 hover:ring-primary-400' }}">
                    @if ($yield !== null && $yield <= 0)
                        <span class="absolute end-2 top-2 rounded-full bg-red-600 px-2 py-0.5 text-[10px] font-bold text-white">
                            {{ __('Out of ingredients') }}
                        </span>
                    @elseif ($yield !== null && $yield <= 5)
                        <span class="absolute end-2 top-2 rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-bold text-amber-700">
                            {{ $yield }} {{ __('left') }}
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
                <p class="col-span-full py-10 text-center text-sm text-chrome-400">{{ __('No products. Seed some or add via POS Products.') }}</p>
            @endforelse
        </div>
    </section>

    {{-- ───────────── Condiment / add-on picker overlay ───────────── --}}
    @if ($pickingCondiments && $condimentLine !== null)
        @php $selectedIds = collect($condimentLine->condiments ?? [])->pluck('id')->map(fn ($v) => (int) $v)->all(); @endphp
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-chrome-900/40 p-4">
            <div class="flex max-h-[80vh] w-full max-w-lg flex-col overflow-hidden rounded-2xl bg-white shadow-pop">
                <div class="flex items-center justify-between gap-4 border-b border-chrome-200 px-5 py-3">
                    <div class="min-w-0">
                        <h2 class="truncate text-base font-semibold text-chrome-900">{{ __('Add-ons') }}</h2>
                        <p class="truncate text-xs text-chrome-500">{{ $condimentLine->name }}</p>
                    </div>
                    <button type="button" wire:click="closeCondiments" class="o-btn-primary text-sm">{{ __('Done') }}</button>
                </div>

                {{-- Tap a row to toggle. Live: each tap recomputes the line + the
                     footer total below. Free add-ons show "Free", priced ones the
                     surcharge. --}}
                <div class="flex-1 divide-y divide-chrome-100 overflow-y-auto">
                    @forelse ($condiments as $cond)
                        @php $isOn = in_array((int) $cond->id, $selectedIds, true); @endphp
                        <button type="button" wire:click="toggleCondiment({{ $cond->id }})" wire:key="cond-{{ $cond->id }}"
                            class="flex w-full items-center justify-between gap-3 px-5 py-3 text-start transition hover:bg-chrome-50 {{ $isOn ? 'bg-primary-50/60' : '' }}">
                            <span class="flex items-center gap-3">
                                <span class="flex size-5 items-center justify-center rounded border {{ $isOn ? 'border-primary-400 bg-primary-400 text-chrome-900' : 'border-chrome-300 text-transparent' }}">
                                    <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.704 5.296a1 1 0 0 1 0 1.408l-7.5 7.5a1 1 0 0 1-1.408 0l-3.5-3.5a1 1 0 0 1 1.408-1.408L8.5 12.09l6.796-6.795a1 1 0 0 1 1.408 0Z" clip-rule="evenodd"/></svg>
                                </span>
                                <span class="font-medium text-chrome-800">{{ $cond->name }}</span>
                            </span>
                            <span class="shrink-0 text-sm font-semibold {{ $cond->price > 0 ? 'text-chrome-700' : 'text-emerald-600' }}">
                                {{ $cond->price > 0 ? '+' . $money($cond->price) : __('Free') }}
                            </span>
                        </button>
                    @empty
                        <p class="px-5 py-10 text-center text-sm text-chrome-400">
                            {{ __('No add-ons for this item.') }}
                            <a href="{{ url('/app/pos/condiment/new') }}" class="text-primary-600 hover:underline">{{ __('Add one') }}</a>.
                        </p>
                    @endforelse
                </div>

                <div class="border-t border-chrome-200 px-5 py-3 text-end text-sm">
                    <span class="text-chrome-500">{{ __('Line total') }}: </span>
                    <span class="font-bold text-chrome-900">{{ $money($condimentLine->total) }}</span>
                </div>
            </div>
        </div>
    @endif

    {{-- ───────────── Customer-discount phone-entry overlay ───────────── --}}
    @if ($enteringPhone)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-chrome-900/40 p-4">
            <div class="w-full max-w-md rounded-2xl bg-white p-5 shadow-pop">
                <div class="mb-1 flex items-center justify-between">
                    <h2 class="text-base font-bold text-chrome-900">{{ __('Customer discount') }}</h2>
                    <button type="button" wire:click="closePhoneEntry"
                        class="text-sm text-chrome-400 hover:text-chrome-700">✕</button>
                </div>
                <p class="mb-4 text-xs text-chrome-500">{{ __('Enter the customer\'s phone to apply their discount. Nothing is saved — the number is only used for this sale\'s discount and WhatsApp receipt.') }}</p>

                <label class="block text-xs font-semibold uppercase tracking-wide text-chrome-500">
                    {{ __('Phone number') }}
                </label>
                <div class="mt-1 flex gap-2">
                    @include('pos::partials.country-picker', [
                        'wireModel' => 'countryCode',
                        'countries' => $whatsappCountries,
                    ])
                    <input type="tel" inputmode="numeric" autofocus
                        wire:model.live.debounce.500ms="localPhone"
                        class="o-input flex-1 text-sm tabular-nums" placeholder="{{ __('Phone number') }}" autocomplete="off">
                </div>

                {{-- Live discount feedback as the cashier types. --}}
                <div class="mt-3 rounded-lg px-3 py-2 text-sm
                    {{ $order->customer_discount_percent > 0
                        ? 'bg-emerald-50 text-emerald-700'
                        : ($localPhone !== '' ? 'bg-amber-50 text-amber-700' : 'bg-chrome-50 text-chrome-500') }}">
                    @if ($order->customer_discount_percent > 0)
                        ✓ {{ rtrim(rtrim(number_format($order->customer_discount_percent, 2), '0'), '.') }}% {{ __('discount applied') }}
                        <span class="font-semibold">−{{ $money($order->customer_discount_total) }}</span>
                    @elseif ($localPhone !== '')
                        {{ __('No discount set for this number.') }}
                    @else
                        {{ __('Enter a phone number above.') }}
                    @endif
                </div>

                <div class="mt-4 flex gap-2">
                    @if ($localPhone !== '')
                        <button type="button" wire:click="clearPhone"
                            class="o-btn-ghost flex-1 justify-center">{{ __('Remove') }}</button>
                    @endif
                    <button type="button" wire:click="closePhoneEntry"
                        class="o-btn-primary flex-1 justify-center">{{ __('Done') }}</button>
                </div>
            </div>
        </div>
    @endif

    {{-- ───────────── Payment overlay ───────────── --}}
    @if ($paying)
        <div class="fixed inset-0 z-40 flex items-center justify-center bg-chrome-900/40 p-4">
            <div class="w-full max-w-md rounded-2xl bg-white p-5 shadow-pop">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-base font-bold text-chrome-900">{{ __('Payment') }}</h2>
                    <button wire:click="$set('paying', false)" class="text-sm text-chrome-400 hover:text-chrome-700">✕</button>
                </div>

                @php $remaining = round($order->total - $order->paymentsTotal(), 2); @endphp
                <div class="rounded-xl bg-chrome-50 p-3 text-sm">
                    @if ($order->customer_discount_total > 0)
                        <div class="flex justify-between text-emerald-600">
                            <span>{{ __('Customer discount') }} ({{ rtrim(rtrim(number_format($order->customer_discount_percent, 2), '0'), '.') }}%)</span>
                            <span>−{{ $money($order->customer_discount_total) }}</span>
                        </div>
                    @endif
                    <div class="flex justify-between"><span class="text-chrome-500">{{ __('Total') }}</span><span class="font-semibold">{{ $money($order->total) }}</span></div>
                    <div class="flex justify-between"><span class="text-chrome-500">{{ __('Paid') }}</span><span>{{ $money($order->paymentsTotal()) }}</span></div>
                    <div class="flex justify-between text-base font-bold {{ $remaining <= 0 ? 'text-emerald-600' : 'text-chrome-900' }}">
                        <span>{{ $remaining > 0 ? __('Remaining') : __('Change') }}</span>
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
                        {{ __('WhatsApp receipt') }}
                        <span class="ms-1 font-normal normal-case tracking-normal text-chrome-400">{{ __('(optional)') }}</span>
                    </label>
                    <div class="mt-1 flex gap-2">
                        @include('pos::partials.country-picker', [
                            'wireModel' => 'countryCode',
                            'countries' => $whatsappCountries,
                        ])
                        <input type="tel" inputmode="numeric" wire:model="localPhone"
                            class="o-input flex-1 text-sm tabular-nums" placeholder="{{ __('Phone number') }}" autocomplete="off">
                    </div>
                </div>

                <div class="mt-3 flex gap-2">
                    <input type="number" step="0.01" wire:model="tendered" class="o-input" placeholder="{{ __('Amount tendered') }}">
                    <button wire:click="addPayment" class="o-btn-primary shrink-0">{{ __('Paid amount') }}</button>
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
                    {{ __('Validate') }}
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
                    @if ($receipt->partner)<p class="text-xs text-chrome-500">{{ __('Customer:') }} {{ $receipt->partner->name }}</p>@endif
                    {{-- Stored as digits-only ("97333123456"); prefix "+" so
                         it reads as an international number. Falls through
                         silently when the cashier didn't capture one — most
                         walk-ins. --}}
                    @if ($receipt->customer_phone)<p class="text-xs text-chrome-500">{{ __('Phone:') }} +{{ $receipt->customer_phone }}</p>@endif
                </div>
                <div class="my-3 border-y border-dashed border-chrome-300 py-2 text-sm">
                    @foreach ($receipt->lines as $l)
                        <div class="flex justify-between">
                            <span>{{ rtrim(rtrim(number_format($l->qty, 3), '0'), '.') }}× {{ $l->name }}</span>
                            <span>{{ $money($l->total) }}</span>
                        </div>
                        @if (!empty($l->condiments))
                            <div class="ps-5 text-xs text-chrome-500">
                                @foreach ($l->condiments as $c)
                                    <div class="flex justify-between">
                                        <span>+ {{ $c['name'] }}</span>
                                        @if (($c['price'] ?? 0) > 0)<span>{{ $money($c['price']) }}</span>@endif
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    @endforeach
                </div>
                <div class="space-y-0.5 text-sm">
                    <div class="flex justify-between text-chrome-500"><span>{{ __('Subtotal') }}</span><span>{{ $money($receipt->subtotal) }}</span></div>
                    <div class="flex justify-between text-chrome-500"><span>{{ __('Tax') }}</span><span>{{ $money($receipt->tax_total) }}</span></div>
                    @if ($receipt->customer_discount_total > 0)
                        <div class="flex justify-between text-emerald-600"><span>{{ __('Customer discount') }} ({{ rtrim(rtrim(number_format($receipt->customer_discount_percent, 2), '0'), '.') }}%)</span><span>−{{ $money($receipt->customer_discount_total) }}</span></div>
                    @endif
                    <div class="flex justify-between font-bold"><span>{{ __('Total') }}</span><span>{{ $money($receipt->total) }}</span></div>
                    @foreach ($receipt->payments as $p)
                        <div class="flex justify-between text-chrome-500"><span>{{ $p->method?->name }}</span><span>{{ $money($p->amount) }}</span></div>
                    @endforeach
                    <div class="flex justify-between font-semibold text-emerald-600"><span>{{ __('Change') }}</span><span>{{ $money($receipt->change_due) }}</span></div>
                </div>
                <p class="mt-3 text-center text-xs text-chrome-400">{{ __('Thank you!') }}</p>
                <div class="mt-4 flex gap-2">
                    <button onclick="window.print()" class="o-btn-ghost flex-1 justify-center">{{ __('Print') }}</button>
                    <button wire:click="newOrder" class="o-btn-primary flex-1 justify-center">{{ __('New order') }}</button>
                </div>
            </div>
        </div>
    @endif

    {{-- Shared split-order overlay (opened via the "Split" header button).
         FQCN form (not the alias) so it resolves even in the test harness,
         where the module provider's boot() — which registers the alias —
         hasn't run after an in-test install. --}}
    @livewire(\Modules\Pos\Livewire\SplitOrderModal::class)
</div>
