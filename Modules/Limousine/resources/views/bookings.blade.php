@php
    // Defined once for the WHOLE page, not inside the row loop. The cancel
    // dialog below also formats money, and a queue with no rows would otherwise
    // leave it undefined — the loop is not a scope the rest of the page can
    // borrow from.
    $money = fn (float $v): string => \App\Erp\Views\ValueFormat::money($v);
    // Whether the online payment portal is switched on — read once for the whole
    // page so the per-row "payment link" button doesn't re-query per trip.
    $portalOn = $this->portalEnabled();
    $pageTitle = $dayHeader['title'] ?? $monthHeader['title'] ?? __('Bookings');
@endphp

<div class="mx-auto w-full p-4 sm:p-6">
    <x-page-header :title="$pageTitle" :subtitle="__('Trip bookings.')" icon="calendar" accent="indigo">
        @if ($dayHeader)
            {{-- How many trips run on the day (cancelled ones left out). --}}
            <x-slot:badge>
                <span class="rounded-full bg-indigo-100 px-2.5 py-0.5 text-sm font-semibold text-indigo-700" data-day-count>{{ $dayHeader['count'] === 1 ? __('1 trip') : __(':count trips', ['count' => $dayHeader['count']]) }}</span>
            </x-slot:badge>
        @endif
        <x-slot:actions>
            @if ($canManage)
                <button type="button" onclick="document.getElementById('import-bookings').classList.toggle('hidden')" class="o-btn-ghost">{{ __('Import') }}</button>
            @endif
            <a href="{{ url('/app/limousine/booking/new') }}" wire:navigate class="o-btn-primary">
                <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10 5a1 1 0 0 1 1 1v3h3a1 1 0 1 1 0 2h-3v3a1 1 0 1 1-2 0v-3H6a1 1 0 1 1 0-2h3V6a1 1 0 0 1 1-1Z"/></svg>
                {{ __('New booking') }}
            </a>
        </x-slot:actions>
    </x-page-header>

    {{-- A single day (the dashboard's Yesterday / Today / Tomorrow cards open
         the list this way): step to the day before or after without going back
         to the dashboard. Flex order mirrors under RTL, so "back" stays at the
         reading start. --}}
    @if ($dayHeader)
        <div class="-mt-2 mb-4 flex items-center justify-between gap-2" data-day-nav>
            <button type="button" wire:click="shiftDay(-1)"
                    class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm font-medium text-indigo-700 ring-1 ring-indigo-100 transition hover:bg-indigo-50"
                    aria-label="{{ __('Previous day') }}">
                <svg class="size-5 rtl:rotate-180" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M17 10a.75.75 0 0 1-.75.75H5.612l4.158 3.96a.75.75 0 1 1-1.04 1.08l-5.5-5.25a.75.75 0 0 1 0-1.08l5.5-5.25a.75.75 0 1 1 1.04 1.08L5.612 9.25H16.25A.75.75 0 0 1 17 10Z" clip-rule="evenodd"/></svg>
                {{ $dayHeader['previous'] }}
                <span class="rounded-full bg-indigo-100 px-1.5 text-xs font-semibold" title="{{ __('Trips') }}" data-previous-count>{{ $dayHeader['previousCount'] }}</span>
            </button>
            <button type="button" wire:click="shiftDay(1)"
                    class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm font-medium text-indigo-700 ring-1 ring-indigo-100 transition hover:bg-indigo-50"
                    aria-label="{{ __('Next day') }}">
                {{ $dayHeader['next'] }}
                <span class="rounded-full bg-indigo-100 px-1.5 text-xs font-semibold" title="{{ __('Trips') }}" data-next-count>{{ $dayHeader['nextCount'] }}</span>
                <svg class="size-5 rtl:rotate-180" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M3 10a.75.75 0 0 1 .75-.75h10.638L10.23 5.29a.75.75 0 1 1 1.04-1.08l5.5 5.25a.75.75 0 0 1 0 1.08l-5.5 5.25a.75.75 0 1 1-1.04-1.08l4.158-3.96H3.75A.75.75 0 0 1 3 10Z" clip-rule="evenodd"/></svg>
            </button>
        </div>
    @elseif ($monthHeader)
        {{-- A whole month (a tab opened from a day page): step a month. --}}
        <div class="-mt-2 mb-4 flex items-center justify-between gap-2" data-month-nav>
            <button type="button" wire:click="shiftMonth(-1)"
                    class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm font-medium text-indigo-700 ring-1 ring-indigo-100 transition hover:bg-indigo-50"
                    aria-label="{{ __('Previous month') }}">
                <svg class="size-5 rtl:rotate-180" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M17 10a.75.75 0 0 1-.75.75H5.612l4.158 3.96a.75.75 0 1 1-1.04 1.08l-5.5-5.25a.75.75 0 0 1 0-1.08l5.5-5.25a.75.75 0 1 1 1.04 1.08L5.612 9.25H16.25A.75.75 0 0 1 17 10Z" clip-rule="evenodd"/></svg>
                {{ $monthHeader['previous'] }}
            </button>
            <button type="button" wire:click="shiftMonth(1)"
                    class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm font-medium text-indigo-700 ring-1 ring-indigo-100 transition hover:bg-indigo-50"
                    aria-label="{{ __('Next month') }}">
                {{ $monthHeader['next'] }}
                <svg class="size-5 rtl:rotate-180" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M3 10a.75.75 0 0 1 .75-.75h10.638L10.23 5.29a.75.75 0 1 1 1.04-1.08l5.5 5.25a.75.75 0 0 1 0 1.08l-5.5 5.25a.75.75 0 1 1-1.04-1.08l4.158-3.96H3.75A.75.75 0 0 1 3 10Z" clip-rule="evenodd"/></svg>
            </button>
        </div>
    @endif

    {{-- Import trips from a CSV (managers). Direct POST — Hostinger-safe. The
         expected columns are the same shape the queue's own export prints, so
         a sheet pulled off the old system's queue screen needs no re-typing.
         Every row lands as settled history — its own invoice built at the
         figure the old system recorded, never through today's live pricing. --}}
    @if ($canManage)
        <div id="import-bookings" class="mb-4 {{ $errors->any() ? '' : 'hidden' }} rounded-2xl border border-dashed border-chrome-300 bg-white p-4">
            <h3 class="mb-1 text-sm font-semibold text-chrome-800">{{ __('Import trips (CSV)') }}</h3>
            <p class="mb-3 text-xs text-chrome-500">{{ __('Columns: Reference, From date, To date, Type, Customer, Amount, Received, Pickup, Drop off, Vehicle, Driver, Company ref., Pax name, Status, Payment. Other columns are ignored. The same customer, pickup time and amount seen before is skipped.') }}</p>
            <form method="POST" action="{{ url('/app/limousine/booking/import') }}" enctype="multipart/form-data" class="flex flex-wrap items-end gap-3">
                @csrf
                <input type="file" name="file" accept=".csv,text/csv,text/plain" required class="text-sm">
                <label class="text-xs text-chrome-600">
                    <span class="mb-1 block">{{ __('Old system list (old-system files only)') }}</span>
                    <select name="list" class="o-input text-sm">
                        <option value="">—</option>
                        <option value="queue" @selected(old('list') === 'queue')>{{ __('Queue') }}</option>
                        <option value="confirmed" @selected(old('list') === 'confirmed')>{{ __('Confirmed') }}</option>
                        <option value="active" @selected(old('list') === 'active')>{{ __('Active') }}</option>
                        <option value="closed" @selected(old('list') === 'closed')>{{ __('Completed') }}</option>
                        <option value="unpaid" @selected(old('list') === 'unpaid')>{{ __('Unpaid') }}</option>
                        <option value="cancelled" @selected(old('list') === 'cancelled')>{{ __('Cancelled') }}</option>
                    </select>
                </label>
                <button type="submit" class="o-btn-primary text-sm">{{ __('Import') }}</button>
            </form>
            <p class="mt-2 text-xs text-chrome-500">{{ __('A file from the old system keeps its booking numbers and lands in the list you choose. An export from this ERP keeps its trip numbers and the status on each row.') }}</p>
            @error('file')<p class="mt-2 text-xs font-medium text-red-600">{{ $message }}</p>@enderror
            @error('list')<p class="mt-2 text-xs font-medium text-red-600">{{ $message }}</p>@enderror
            @if (session('toast'))
                <p class="mt-2 text-xs font-medium text-emerald-600">{{ session('toast') }}</p>
            @endif
        </div>
    @endif

    {{-- Carries the reference, so it is the line the office forwards to the
         customer — which means it has to be copyable, not just readable. A
         "View" button, when the flash carries the booking's id (a create or
         an edit from this list), opens the same preview straight away —
         checking what was just booked shouldn't mean hunting the row below. --}}
    @if (session('booking_status'))
        <div class="mb-4 flex items-start justify-between gap-3 rounded-lg bg-primary-50 px-4 py-2.5 text-sm font-medium text-chrome-800 ring-1 ring-primary-200"
             x-data="{ copied: false }">
            @php
                // The trip details go under the message and into the copy, so
                // what the office forwards says when, where and which car.
                $manyTrips = count($savedTrips) > 1;
                $bannerCopy = (string) session('booking_status');
                foreach ($savedTrips as $t) {
                    $block = $manyTrips ? [__('Ref. #') . ' ' . $t['reference'], ...$t['lines']] : $t['lines'];
                    if ($block !== []) {
                        $bannerCopy .= "\n\n" . implode("\n", $block);
                    }
                }
            @endphp
            <div class="min-w-0">
                <span class="whitespace-pre-line">{{ session('booking_status') }}</span>
                @foreach ($savedTrips as $t)
                    @if ($t['lines'] !== [])
                        <div class="mt-2 border-t border-primary-200 pt-2 text-xs font-normal text-chrome-700" data-saved-trip>
                            @if ($manyTrips)
                                <div class="font-semibold text-chrome-800">{{ __('Ref. #') }} {{ $t['reference'] }}</div>
                            @endif
                            @foreach ($t['lines'] as $line)
                                <div class="break-words">{{ $line }}</div>
                            @endforeach
                        </div>
                    @endif
                @endforeach
            </div>
            <div class="flex shrink-0 items-center gap-1">
                <button type="button"
                        x-on:click="
                            $store.clip.copy(@js($bannerCopy));
                            copied = true; setTimeout(() => copied = false, 1500)
                        "
                        :title="copied ? @js(__('Copied')) : @js(__('Copy this message'))"
                        :aria-label="copied ? @js(__('Copied')) : @js(__('Copy this message'))"
                        class="rounded-md p-1 text-chrome-500 transition hover:bg-primary-100 hover:text-chrome-800">
                    <svg x-show="! copied" class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 0 1-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 0 1 1.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 0 0-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 0 1-1.125-1.125v-9.25"/>
                    </svg>
                    <svg x-show="copied" x-cloak class="size-4 text-emerald-600" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/>
                    </svg>
                </button>

                {{-- The two things done right after booking: look at it, or send
                     the customer a payment link (one for the whole booking,
                     every trip in it). Same `rowActionsMenu` (fixed panel,
                     flips above when there is no room below) as the rows. --}}
                @if ($savedBooking !== null)
                    @php
                        $bItem = 'flex w-full items-center gap-2 px-3 py-1.5 text-start text-xs font-medium text-chrome-800 hover:bg-chrome-50';
                        $bIcon = 'size-4 shrink-0 text-chrome-400';
                        $bTrips = $savedBooking->legs;
                        $bMany = $bTrips->count() > 1;
                    @endphp
                    <div x-data="rowActionsMenu" @click.outside="open = false"
                         @keydown.escape.window="open = false" class="relative" data-saved-booking-actions>
                        <button type="button" @click="toggle($event.currentTarget)"
                            class="inline-flex size-7 items-center justify-center rounded-lg text-chrome-700 transition hover:bg-primary-100"
                            :aria-expanded="open" aria-haspopup="true"
                            title="{{ __('Actions') }}" aria-label="{{ __('Actions') }}">
                            <svg class="size-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path d="M10 3a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3Zm0 5.5a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3Zm0 5.5a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3Z"/>
                            </svg>
                        </button>

                        <div x-ref="panel" x-show="open" x-cloak x-transition.opacity.duration.100ms
                             :style="(coords.top === null ? 'bottom:' + coords.bottom + 'px;' : 'top:' + coords.top + 'px;') + (coords.rtl ? 'right:' + coords.right + 'px' : 'left:' + coords.left + 'px')"
                             class="fixed z-30 max-h-[70vh] w-60 overflow-y-auto overflow-x-hidden rounded-lg border border-chrome-200 bg-white py-1 shadow-pop">
                            {{-- The full booking page, not the preview (the owner's call, 2026-11-24). --}}
                            <a href="{{ url('/app/limousine/booking/' . $savedBooking->id) }}" wire:navigate @click="open = false" class="{{ $bItem }}" data-banner-open-booking>
                                <svg class="{{ $bIcon }}" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/>
                                </svg>
                                {{ __('Open full booking') }}
                            </a>
                            {{-- One link for the whole booking: it pays every trip in it. --}}
                            @if ($portalOn && $bTrips->isNotEmpty())
                                <button type="button" @click="open = false" wire:click="openPaymentLink({{ $bTrips->first()->id }})" class="{{ $bItem }}" data-banner-payment-link>
                                    <svg class="{{ $bIcon }}" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3M3.75 19.5h16.5A2.25 2.25 0 0 0 22.5 17.25V6.75A2.25 2.25 0 0 0 20.25 4.5H3.75A2.25 2.25 0 0 0 1.5 6.75v10.5A2.25 2.25 0 0 0 3.75 19.5Z"/>
                                    </svg>
                                    {{ $bMany ? __('Create payment link — all trips') : __('Create payment link') }}
                                </button>
                            @endif
                        </div>
                    </div>
                @endif
            </div>
        </div>
    @endif

    @php
        // "All" is every trip that is happening or happened — the same view the
        // schedule cards open. Called-off trips stay in their own Cancelled tab
        // (a search still brings them back), so All is never padded with work
        // that is not going ahead.
        $tabs = [
            'all' => __('All'),
            // Trips due within a day (or just past) with no car or no driver:
            // the customer is promised both 12 hours before pick-up.
            'needs_crew' => __('Needs car & driver'),
            'queue' => __('Queue'),
            'confirmed' => __('Confirmed'),
            'active' => __('Active'),
            'completed' => __('Completed'),
            // Not a stage a trip is at — money still to collect, whatever stage
            // the trip reached.
            'unpaid' => __('Unpaid'),
            'cancelled' => __('Cancelled'),
        ];
    @endphp
    @if ($countsMonth)
        <p class="mb-1 text-xs text-chrome-500" data-counts-month>{{ __('Counts for :month', ['month' => $countsMonth]) }}</p>
    @endif
    <div class="mb-4 flex flex-wrap items-center gap-1 border-b border-chrome-200">
        @foreach ($tabs as $key => $label)
            @php $n = match ($key) {
                'unpaid' => $unpaidCount,
                'needs_crew' => $needsCrewCount,
                // What the All tab lists: every status but cancelled.
                'all' => (int) $counts->except('cancelled')->sum(),
                default => (int) $counts->get($key, 0),
            }; @endphp
            <button wire:click="openTab('{{ $key }}')"
                class="-mb-px flex items-center gap-1.5 border-b-2 px-4 py-2 text-sm font-medium {{ $tab === $key ? 'border-primary-600 text-primary-700' : 'border-transparent text-chrome-800 hover:text-chrome-900' }}">
                {{ $label }}
                {{-- Any trip waiting for a crew is a customer waiting: red, not grey. --}}
                <span class="rounded-full px-1.5 text-[11px] {{ $key === 'needs_crew' && $n > 0 ? 'bg-red-600 font-semibold text-white' : 'bg-chrome-100 text-chrome-700' }}"
                      @if ($key === 'needs_crew') data-needs-crew-count @endif>{{ $n }}</span>
            </button>
        @endforeach
    </div>

    {{-- Who owes, on the Unpaid tab: one box for companies, one for
         individuals. A box shows how many customers of that kind owe money;
         pressing it lists their names under it and narrows the list below to
         their trips. Pressing a name narrows it to that one customer. --}}
    @if ($tab === 'unpaid' && $unpaidCustomers !== [])
        @php
            $kindBoxes = [
                'company' => __('Companies'),
                'individual' => __('Individuals'),
            ];
        @endphp
        <div class="mb-4" data-unpaid-kinds>
            <div class="grid grid-cols-2 gap-3 sm:max-w-xl">
                @foreach ($kindBoxes as $kindKey => $kindLabel)
                    @php
                        $kindList = $unpaidCustomers[$kindKey] ?? [];
                        $kindOwed = array_sum(array_column($kindList, 'balance'));
                        $kindTrips = array_sum(array_column($kindList, 'trips'));
                    @endphp
                    <button type="button" wire:click="setCustomerKind('{{ $kindKey }}')"
                            aria-pressed="{{ $activeKind === $kindKey ? 'true' : 'false' }}"
                            data-unpaid-kind="{{ $kindKey }}"
                            @class(['rounded-xl border p-3 text-start transition',
                                    'border-primary-500 bg-primary-50 ring-2 ring-primary-400' => $activeKind === $kindKey,
                                    'border-chrome-200 bg-white hover:border-chrome-300 hover:bg-chrome-50' => $activeKind !== $kindKey])>
                        <div class="flex items-baseline justify-between gap-2">
                            <span class="text-sm font-medium text-chrome-700">{{ $kindLabel }}</span>
                            <span class="text-2xl font-bold tracking-tight text-chrome-900" data-unpaid-kind-count>{{ count($kindList) }}</span>
                        </div>
                        <div class="mt-1 text-xs text-chrome-500">
                            {{ __(':count trips', ['count' => $kindTrips]) }}
                            · <span class="font-semibold text-red-600">{{ \App\Erp\Views\ValueFormat::money($kindOwed) }}</span>
                        </div>
                    </button>
                @endforeach
            </div>

            @if ($activeKind !== '')
                @php $names = $unpaidCustomers[$activeKind] ?? []; @endphp
                <div class="mt-3 rounded-xl border border-chrome-200 bg-white p-3" data-unpaid-names>
                    <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                        <span class="text-xs font-medium text-chrome-700">
                            {{ $kindBoxes[$activeKind] }} — {{ __('press a name to see only their trips') }}
                        </span>
                        <button type="button" wire:click="setCustomerKind('')" class="text-xs font-medium text-primary-700 hover:underline">{{ __('Show everyone') }}</button>
                    </div>
                    @if ($names === [])
                        <p class="text-sm text-chrome-500">{{ __('Nobody of this kind owes anything here.') }}</p>
                    @else
                        <div class="flex max-h-56 flex-wrap gap-1.5 overflow-y-auto">
                            @foreach ($names as $who)
                                @php $picked = $who['id'] > 0 && $activeCustomer === $who['id']; @endphp
                                <button type="button"
                                        @if ($who['id'] > 0) wire:click="setUnpaidCustomer({{ $who['id'] }})" @else disabled @endif
                                        data-unpaid-name="{{ $who['id'] }}"
                                        @class(['rounded-lg px-2.5 py-1.5 text-start text-xs ring-1 transition',
                                                'bg-primary-400 font-semibold text-chrome-900 ring-primary-500' => $picked,
                                                'bg-white text-chrome-800 ring-chrome-200 hover:bg-chrome-50' => ! $picked])>
                                    <span class="font-medium">{{ $who['name'] }}</span>
                                    <span class="ms-1 text-chrome-500">{{ $who['trips'] }}</span>
                                    <span class="ms-1 font-semibold {{ $picked ? 'text-chrome-900' : 'text-red-600' }}">{{ \App\Erp\Views\ValueFormat::money($who['balance']) }}</span>
                                </button>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif
        </div>
    @endif

    <div class="mb-4 flex flex-wrap items-end gap-3">
        <div class="min-w-[16rem] flex-1">
            <label class="mb-1 block text-xs font-medium text-chrome-700">{{ __('Search') }}</label>
            <div class="relative">
                <span class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-3 text-chrome-400">
                    <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 1 0 3.4 9.82l3.14 3.14a.75.75 0 1 0 1.06-1.06l-3.14-3.14A5.5 5.5 0 0 0 9 3.5ZM5 9a4 4 0 1 1 8 0 4 4 0 0 1-8 0Z" clip-rule="evenodd"/></svg>
                </span>
                {{-- Debounced: results stream as the user types without a
                     round-trip per keystroke. --}}
                <input type="search" wire:model.live.debounce.300ms="search"
                       class="o-input w-full ps-9 text-sm"
                       placeholder="{{ __('Reference, customer, passenger, route…') }}">
            </div>
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium text-chrome-700">{{ __('Pick-up from') }}</label>
            <x-date-field wire:model.live="from" class="o-input text-sm" />
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium text-chrome-700">{{ __('Pick-up to') }}</label>
            <x-date-field wire:model.live="to" class="o-input text-sm" />
        </div>
        @if ($from !== '' || $to !== '' || $search !== '')
            {{-- Clears the search too, so one button resets the whole filter. --}}
            <button wire:click="$set('from', ''); $set('to', ''); $set('search', '')" class="pb-2 text-sm text-chrome-500 hover:underline">{{ __('Clear') }}</button>
        @endif

        {{-- Twenty-five to glance at, three hundred for going through the
             month properly. Rides in the URL with the filters, so a view that
             was set up stays set up.

             Off the phone: three hundred rows is not something anyone reads
             on one, and the row of chips was taking space from the filters
             that are used there. A phone keeps the twenty-five it opens on
             and pages through them; a size chosen on a laptop still rides in
             the URL, so a shared link opens the same way on either. --}}
        <div class="hidden items-center gap-1 pb-1 sm:flex">
            <span class="text-xs text-chrome-700">{{ __('Show') }}</span>
            @foreach ($perPageOptions as $size)
                <button type="button" wire:click="setPerPage({{ $size }})"
                        class="rounded-lg border px-2.5 py-1 text-xs transition {{ $perPage === $size ? 'border-primary-500 bg-primary-50 font-medium text-primary-700' : 'border-chrome-200 text-chrome-600 hover:bg-chrome-50' }}">
                    {{ $size }}
                </button>
            @endforeach
        </div>
    </div>

    {{-- Export bar. CSV / Excel / PDF / Print are server-rendered from the same
         rows as the table (filters ride along in the query string); Copy lifts
         the rendered table client-side, so it needs no endpoint. --}}
    <div class="mb-3 flex flex-wrap items-center gap-2">
        <a href="{{ url('/app/limousine/booking/export/csv') }}?{{ $exportQuery }}"
           class="rounded-lg border border-chrome-200 px-3 py-1.5 text-xs font-medium text-chrome-700 transition hover:bg-chrome-50">{{ __('CSV') }}</a>
        <a href="{{ url('/app/limousine/booking/export/excel') }}?{{ $exportQuery }}"
           class="rounded-lg border border-chrome-200 px-3 py-1.5 text-xs font-medium text-chrome-700 transition hover:bg-chrome-50">{{ __('Excel') }}</a>
        <a href="{{ url('/app/limousine/booking/export/pdf') }}?{{ $exportQuery }}"
           class="rounded-lg border border-chrome-200 px-3 py-1.5 text-xs font-medium text-chrome-700 transition hover:bg-chrome-50">{{ __('PDF') }}</a>
        <a href="{{ url('/app/limousine/booking/export/print') }}?{{ $exportQuery }}" target="_blank" rel="noopener"
           class="rounded-lg border border-chrome-200 px-3 py-1.5 text-xs font-medium text-chrome-700 transition hover:bg-chrome-50">{{ __('Print') }}</a>
    </div>

    {{-- Driver tabs on a day page (Yesterday / Today / Tomorrow). On a phone a
         notch sits at the top of the list and stays there while scrolling:
         pull it down (or tap it) for one tab per driver who has trips that
         day, plus "No driver" for the trips nobody has yet. Laptops have the
         Driver column to sort by, so they only see which driver is picked. --}}
    @if ($dayHeader && ($dayDrivers !== [] || $activeDriver !== ''))
        @php
            $activeLabel = $activeDriver === ''
                ? __('All drivers')
                : (collect($dayDrivers)->firstWhere('key', $activeDriver)['label'] ?? __('No trips'));
            $dayTotal = array_sum(array_column($dayDrivers, 'count'));
        @endphp

        @if ($activeDriver !== '')
            <div class="mb-3 hidden items-center gap-2 text-sm sm:flex" data-driver-active>
                <span class="text-chrome-600">{{ __('Driver') }}:</span>
                <span class="rounded-full bg-indigo-600 px-2.5 py-0.5 font-semibold text-white">{{ $activeLabel }}</span>
                <button type="button" wire:click="setDriver('')" class="text-xs font-medium text-indigo-700 hover:underline">{{ __('Show all drivers') }}</button>
            </div>
        @endif

        <div class="sticky top-0 z-30 -mx-4 mb-3 border-b border-chrome-200 bg-white/95 px-4 shadow-sm backdrop-blur sm:hidden"
             x-data="{ open: false, y: null }"
             x-on:touchstart.passive="y = $event.touches[0].clientY"
             x-on:touchmove.passive="if (y !== null) { const d = $event.touches[0].clientY - y; if (d > 30) { open = true; y = null } else if (d < -30) { open = false; y = null } }"
             x-on:touchend.passive="y = null"
             x-on:keydown.escape.window="open = false"
             data-driver-notch>
            <div x-show="open" x-transition.opacity.duration.150ms x-cloak class="flex flex-wrap gap-1.5 pt-3" data-driver-tabs>
                <button type="button" wire:click="setDriver('')" x-on:click="open = false"
                        @class(['rounded-full px-3 py-1.5 text-xs font-semibold ring-1 transition',
                                'bg-indigo-600 text-white ring-indigo-600' => $activeDriver === '',
                                'bg-white text-chrome-700 ring-chrome-200' => $activeDriver !== ''])>
                    {{ __('All') }} <span class="ms-0.5 opacity-75">{{ $dayTotal }}</span>
                </button>
                @foreach ($dayDrivers as $d)
                    <button type="button" wire:click="setDriver({{ \Illuminate\Support\Js::from($d['key']) }})" x-on:click="open = false"
                            data-driver-tab="{{ $d['key'] }}"
                            @class(['rounded-full px-3 py-1.5 text-xs font-semibold ring-1 transition',
                                    'bg-indigo-600 text-white ring-indigo-600' => $activeDriver === $d['key'],
                                    'bg-red-50 text-red-700 ring-red-200' => $activeDriver !== $d['key'] && $d['key'] === \Modules\Limousine\Services\LimoQueueRows::DRIVER_NONE,
                                    'bg-white text-chrome-700 ring-chrome-200' => $activeDriver !== $d['key'] && $d['key'] !== \Modules\Limousine\Services\LimoQueueRows::DRIVER_NONE])>
                        {{ $d['label'] }} <span class="ms-0.5 opacity-75">{{ $d['count'] }}</span>
                    </button>
                @endforeach
            </div>
            <button type="button" x-on:click="open = ! open" x-bind:aria-expanded="open"
                    class="flex w-full flex-col items-center gap-1 py-2" aria-label="{{ __('Drivers') }}">
                <span class="h-1.5 w-10 rounded-full bg-chrome-300"></span>
                <span class="text-xs font-medium text-chrome-600">
                    {{ __('Drivers') }} · <span class="font-semibold text-indigo-700">{{ $activeLabel }}</span>
                </span>
            </button>
        </div>
    @endif

    {{-- 17 data columns can never fit a phone, so rather than force a sideways
         scroll the table sheds columns as the screen narrows: what identifies
         and actions a job always stays, the rest return as there is room.
           phone  reference (with added by under it) · balance under the amount ·
                  date · type · customer · amount · pickup · drop off ·
                  vehicle · driver · added by · payment · actions
           sm     + no. · to date
           lg     + received and balance as their own columns (narrower
                  screens show them under the reference — see that cell)
           md     + status
           lg     everything else (comments,
                  booked time)

         Note the HEADER's width class comes from $vis below while each body
         <td> carries its own. They are two halves of one column and have to
         agree, or the table misaligns at that width — pinned by a test.
         `lg` (1024px), not the far wider `xl`/`2xl` this used to wait for —
         a normal work laptop clears 1024px with room to spare, and a
         Supervisor doing dispatch work needs the money/driver columns as
         much as an admin does; those aren't gated by role anywhere, only by
         how wide the screen was. Exports and Print carry ALL columns
         whatever the screen, so nothing is lost — it is only hidden here. --}}
    @php
        $vis = [
            'reference' => '',
            'from_date' => '',
            'to_date' => 'hidden sm:table-cell',
            // Off on a phone (the owner's call): the copied trip message and the
            // full booking still say what kind of job it is.
            'type' => 'hidden sm:table-cell',
            'customer' => '',
            'amount' => '',
            'received' => 'hidden lg:table-cell',
            'balance' => 'hidden lg:table-cell',
            'pickup' => '',
            'dropoff' => '',
            'vehicle' => '',
            // On a phone too (the owner asked): who is driving is checked from the road.
            'driver' => '',
            // On a phone it reads under the trip number instead (the owner's call).
            'added_by' => 'hidden sm:table-cell',
            'comments' => 'hidden lg:table-cell',
            'status' => 'hidden md:table-cell',
            'payment' => '',
        ];

        // The service's own column order, which puts the type and the customer
        // ahead of the price — what the job IS before what it costs. The screen
        // used to pull the price up beside the dates; the desk asked for it
        // back. Taken from the service rather than retyped, so a column added
        // there appears here too and the exports cannot disagree with the screen.
        $order = array_keys($headings);

        // On screen, Booked time is read UNDER Added by in the same cell (who
        // took the booking and when), so it has no column of its own. The
        // exports keep the service's order, which the office's spreadsheets expect.
        $order = array_values(array_diff($order, ['booked_time']));

        // ETA, after the dates, on the tabs where a car is on its way or was:
        // Queue, Active and Completed. Screen only, like Booked time's place.
        $showEta = in_array($tab, [\Modules\Limousine\Models\LimoLeg::STATUS_QUEUE, \Modules\Limousine\Models\LimoLeg::STATUS_ACTIVE, \Modules\Limousine\Models\LimoLeg::STATUS_COMPLETED], true);
        if ($showEta) {
            $headings['eta'] = __('ETA');
            array_splice($order, (int) array_search('to_date', $order, true) + 1, 0, ['eta']);
        }
    @endphp
    <div class="overflow-x-auto rounded-2xl bg-white shadow-sm ring-1 ring-chrome-900/[0.06]">
        <table id="limo-queue" class="w-full table-auto divide-y divide-chrome-100 text-xs">
            <thead class="bg-chrome-50 text-xs font-semibold uppercase tracking-wide text-chrome-700">
                <tr>
                    <th class="px-2 py-2 text-start">{{ __('Sl No.') }}</th>
                    {{-- Every column sorts, both ways. The arrow is always drawn so
                         a column reads as sortable before anyone clicks it, and
                         only darkens on the one actually doing the sorting. --}}
                    @foreach ($order as $key)
                        @php
                            $label = $headings[$key];
                            $isMoney = in_array($key, ['amount', 'received', 'balance'], true);
                            $sorted = $sort === $key;
                            // A phone shows one date column, so "From date" there is
                            // just the date — the pair only needs telling apart once
                            // "To date" joins it at sm.
                            $phoneLabel = $key === 'from_date' ? __('Date') : null;
                        @endphp
                        {{-- if/else, never @continue inside the @if: Livewire marks
                             each @if with an opening and closing comment, and a
                             @continue skips the closing one. The browser then could
                             not redraw the header when the ETA column came or went,
                             and the list stopped following the tabs and the search. --}}
                        @if ($key === 'eta')
                            {{-- Worked out per trip (start + estimated time), not a
                                 stored column, so it does not sort. --}}
                            <th class="whitespace-nowrap px-2 py-2 text-start" data-eta-heading
                                title="{{ __('Estimated time of arrival') }}">{{ $label }}</th>
                        @else
                        <th class="px-2 py-2 {{ $isMoney ? 'text-end' : 'text-start' }} {{ $vis[$key] ?? '' }}"
                            @if ($sorted) aria-sort="{{ $dir === 'asc' ? 'ascending' : 'descending' }}" @endif>
                            <button type="button" wire:click="sortBy('{{ $key }}')"
                                class="inline-flex items-center gap-1 transition hover:text-chrome-800 {{ $isMoney ? 'flex-row-reverse' : '' }} {{ $sorted ? 'text-chrome-900' : '' }}"
                                title="{{ __('Sort by :column', ['column' => $label]) }}">
                                @if ($phoneLabel !== null)
                                    <span class="sm:hidden">{{ $phoneLabel }}</span>
                                    <span class="hidden sm:inline">{{ $label }}</span>
                                @else
                                    <span>{{ $label }}</span>
                                @endif
                                {{-- Hidden on a phone: at this width the arrows cost more room than they
                                     earn, and tapping the heading still sorts. The column doing
                                     the sorting stays darker than the rest, so it is not lost. --}}
                                <svg class="hidden size-3 shrink-0 transition sm:inline-block {{ $sorted ? 'text-primary-600' : 'text-chrome-300' }} {{ $sorted && $dir === 'asc' ? 'rotate-180' : '' }}"
                                     viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path fill-rule="evenodd" d="M10 15a1 1 0 0 1-.71-.29l-5-5a1 1 0 1 1 1.42-1.42L10 12.59l4.29-4.3a1 1 0 1 1 1.42 1.42l-5 5A1 1 0 0 1 10 15Z" clip-rule="evenodd"/>
                                </svg>
                            </button>
                            @if ($key === 'added_by')
                                {{-- Booked time shares this column, so it sorts from here. --}}
                                <button type="button" wire:click="sortBy('booked_time')"
                                    class="mt-0.5 flex items-center gap-1 text-[10px] font-medium normal-case tracking-normal transition hover:text-chrome-800 {{ $sort === 'booked_time' ? 'text-chrome-900' : 'text-chrome-500' }}"
                                    title="{{ __('Sort by :column', ['column' => __('Booked time')]) }}"
                                    @if ($sort === 'booked_time') aria-sort="{{ $dir === 'asc' ? 'ascending' : 'descending' }}" @endif>
                                    <span>{{ __('Booked time') }}</span>
                                    <svg class="hidden size-3 shrink-0 transition sm:inline-block {{ $sort === 'booked_time' ? 'text-primary-600' : 'text-chrome-300' }} {{ $sort === 'booked_time' && $dir === 'asc' ? 'rotate-180' : '' }}"
                                         viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                        <path fill-rule="evenodd" d="M10 15a1 1 0 0 1-.71-.29l-5-5a1 1 0 1 1 1.42-1.42L10 12.59l4.29-4.3a1 1 0 1 1 1.42 1.42l-5 5A1 1 0 0 1 10 15Z" clip-rule="evenodd"/>
                                    </svg>
                                </button>
                            @endif
                        </th>
                        @endif
                    @endforeach
                    {{-- Columns are sized to fit the window, but a narrow laptop can still
                         overflow — keep Open/Edit pinned to the trailing edge so they can
                         never end up off-screen. --}}
                    <th class="sticky end-0 z-20 bg-chrome-50 px-2 py-2 text-end shadow-[-8px_0_8px_-8px_rgba(0,0,0,0.12)]">{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-chrome-50">
                {{-- One row per LEG: each is dispatched separately, with its own
                     reference, car and status. Payment belongs to the parent
                     booking, so every leg of a paid job reads "paid". --}}
                @forelse ($legs as $i => $leg)
                    @php
                        $row = $rows[$leg->id];
                        $sb = [
                            'queue' => 'bg-amber-100 text-amber-700',
                            'confirmed' => 'bg-sky-100 text-sky-700',
                            'active' => 'bg-indigo-100 text-indigo-700',
                            'completed' => 'bg-emerald-100 text-emerald-700',
                            'cancelled' => 'bg-red-100 text-red-700',
                        ][$row['status']] ?? 'bg-chrome-200 text-chrome-700';
                        $next = [
                            'queue' => ['confirmed', __('Confirm')],
                            'confirmed' => ['active', __('Start trip')],
                            'active' => ['completed', __('Complete')],
                        ][$row['status']] ?? null;
                        // A finished or cancelled trip is history: it can be read,
                        // printed and signed for, but nothing about it changes any
                        // more. Leaving Edit and Assign live on a done trip invites
                        // a change that contradicts what was actually driven.
                        $locked = in_array($row['status'], ['completed', 'cancelled'], true);
                        $mayEdit = $canAssign && ! $locked;
                    @endphp
                    {{-- `group` so the pinned Actions cell can mirror the row hover
                         (it needs its own background to sit above the scroll). --}}
                    <tr wire:key="leg-{{ $leg->id }}" class="group hover:bg-chrome-50">
                        <td class="px-2 py-2 text-chrome-600" data-sl-no>{{ $legs->firstItem() + $i }}</td>
                        {{-- The reference IS the copy button: press it and the whole
                             trip lands on the clipboard, formatted for WhatsApp. That
                             is what the office does with a booking, so it should be
                             one press from the number they are already looking at.
                             (The quick-preview modal that briefly lived behind this
                             button is still reachable from the "View" link on the
                             just-saved banner — only the reference's own click
                             reverted, per the office's request.) --}}
                        <td class="px-2 py-2 font-medium text-chrome-900">
                            @if (($whatsapp[$leg->id] ?? '') === '' && \Modules\Limousine\Support\TripDetailsLock::hides($leg))
                                {{-- Nothing to copy until a car and a driver are on it. --}}
                                <span class="font-medium text-chrome-700" data-copy-locked
                                      title="{{ __('Assign a car and a driver to copy this trip') }}">🔒 {{ $row['reference'] ?: '—' }}</span>
                            @else
                                <button type="button" x-data="{ done: false }"
                                        x-on:click="
                                            $store.limoTrip.copy(@js($whatsapp[$leg->id] ?? ''));
                                            done = true; setTimeout(() => done = false, 1500)
                                        "
                                        title="{{ __('Copy trip details for WhatsApp') }}"
                                        class="text-start font-medium text-primary-700 hover:underline">
                                    <span x-show="! done">{{ $row['reference'] ?: '—' }}</span>
                                    <span x-show="done" x-cloak class="text-emerald-600">✓ {{ __('Copied') }}</span>
                                </button>
                            @endif
                            <span class="block text-[11px] font-normal text-chrome-600">{{ $row['booking_reference'] }}</span>
                            {{-- Phone only: who took the booking and when, under the number
                                 (the Added by column is off below sm). --}}
                            <div class="mt-0.5 text-[11px] font-normal leading-tight text-chrome-500 sm:hidden" data-added-by-phone>
                                <div class="text-chrome-700">{{ $row['added_by'] ?: '—' }}</div>
                                @php [$phoneDate, $phoneClock] = array_pad(explode(' ', (string) $row['booked_time'], 2), 2, ''); @endphp
                                @if ($phoneDate !== '')
                                    <div class="whitespace-nowrap">{{ $phoneDate }}</div>
                                @endif
                                @if ($phoneClock !== '')
                                    <div>{{ $phoneClock }}</div>
                                @endif
                            </div>
                            @if (isset($fromWebsite[(int) $leg->legable_id]))
                                <span class="mt-0.5 inline-block rounded bg-violet-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-violet-700" data-from-website>{{ __('Website') }}</span>
                            @endif
                        </td>
                        <td class="px-2 py-2 text-chrome-900">{{ $row['from_date'] ?: '—' }}</td>
                        <td class="hidden px-2 py-2 text-chrome-900 sm:table-cell">{{ $row['to_date'] ?: '—' }}</td>
                        @if ($showEta)
                            <td class="whitespace-nowrap px-2 py-2 font-medium text-chrome-900" data-eta>{{ $row['eta'] ?: '—' }}</td>
                        @endif
                        <td class="hidden px-2 py-2 text-chrome-900 sm:table-cell">{{ $row['type'] }}</td>
                        {{-- The name is the way into their account: what they
                             have asked for, what is billed and what is owed.
                             stopPropagation so it doesn't also open the row. --}}
                        <td class="px-2 py-2 text-chrome-900" onclick="event.stopPropagation()">
                            @if ($row['customer'] && $row['customer_id'])
                                <a href="{{ url('/app/limousine/customer/' . $row['customer_id'] . '/summary') }}" wire:navigate
                                   class="font-medium text-primary-700 hover:underline">{{ $row['customer'] }}</a>
                            @else
                                {{ $row['customer'] ?: '—' }}
                            @endif
                            {{-- A company is the account, not who rides: the passenger's
                                 name goes under it. --}}
                            @if ($row['passenger'] !== '')
                                <span class="mt-0.5 block text-[11px] text-chrome-700" data-passenger>{{ __('PAX') }}: {{ $row['passenger'] }}</span>
                            @endif
                            {{-- The number to ring: the passenger's for a company,
                                 the customer's own otherwise. --}}
                            @if ($row['contact_phone'] !== '')
                                <a href="tel:{{ preg_replace('/[^0-9+]/', '', $row['contact_phone']) }}"
                                   class="mt-0.5 block whitespace-nowrap text-[11px] text-chrome-600 hover:underline"
                                   data-contact-phone><span dir="ltr">{{ $row['contact_phone'] }}</span></a>
                            @endif
                        </td>
                        {{-- THIS trip's own price. It sits after what the job is
                             and who it is for, which is the order the service's
                             own column list uses — so the screen and the exports
                             read the same way round. --}}
                        <td class="px-2 py-2 text-end font-medium text-chrome-900"
                            title="{{ __('Price of this trip') }}">{{ $money($row['amount']) }}
                            {{-- Below lg the Balance column is hidden, so what is still
                                 owed reads under the price (the owner's call). It is this
                                 trip's part, as in that column. --}}
                            @if ($row['balance'] > 0)
                                <span class="mt-0.5 block whitespace-nowrap text-[11px] font-semibold text-amber-700 lg:hidden" data-amount-balance
                                      title="{{ __('For this trip. Still owed on the whole booking :reference: :total.', ['reference' => $row['booking_reference'], 'total' => $money($row['booking_balance'] ?? $row['balance'])]) }}">{{ __('Balance') }} {{ $money($row['balance']) }}</span>
                            @endif
                        </td>
                        {{-- Received and Balance are THIS TRIP's part of the booking's
                             money (LimoBooking::tripShares, the owner's call 2026-11-28):
                             two 45 BD trips booked together showed "90 owed" on each and
                             read as 180. Money pays the earliest trip first. The tooltip
                             gives the whole booking's balance. --}}
                        <td class="hidden px-2 py-2 text-end text-emerald-700 lg:table-cell"
                            title="{{ __('For this trip. Money received on booking :reference pays the earliest trip first.', ['reference' => $row['booking_reference']]) }}">{{ $money($row['received']) }}</td>
                        <td class="hidden px-2 py-2 text-end lg:table-cell {{ $row['balance'] > 0 ? 'text-amber-700' : 'text-chrome-600' }}"
                            title="{{ __('For this trip. Still owed on the whole booking :reference: :total.', ['reference' => $row['booking_reference'], 'total' => $money($row['booking_balance'] ?? $row['balance'])]) }}">{{ $money($row['balance']) }}</td>
                        {{-- The country's short code sits above each place (KSA over
                             "Dammam"), so a cross-border trip reads at a glance. --}}
                        <td class="px-2 py-2 text-chrome-900">
                            @if ($row['pickup_country'] !== '')
                                <span class="block text-[11px] font-semibold uppercase text-chrome-600" data-place-country="pickup">{{ $row['pickup_country'] }}</span>
                            @endif
                            {{ $row['pickup'] ?: '—' }}
                        </td>
                        <td class="px-2 py-2 text-chrome-900">
                            @if ($row['dropoff_country'] !== '')
                                <span class="block text-[11px] font-semibold uppercase text-chrome-600" data-place-country="dropoff">{{ $row['dropoff_country'] }}</span>
                            @endif
                            {{ $row['dropoff'] ?: '—' }}
                        </td>
                        {{-- A car is assigned late, but the KIND of car is agreed when
                             the trip is booked — so an unassigned row said nothing at
                             all about a fact the office already knew. The requested
                             type now reads above the button, and stays as a quiet
                             second line once a real car takes over, so dispatch can
                             see at a glance whether what was sent matches what was
                             asked for. --}}
                        <td class="px-2 py-2">
                            @php $type = $row['vehicle_type']; @endphp
                            @if ($row['vehicle'] !== '')
                                <span class="text-chrome-900">{{ $row['vehicle'] }}</span>
                                @if (($row['outside_company'] ?? '') !== '')
                                    <span class="block text-[11px] font-medium text-amber-700" data-outside-company>{{ __('Other company: :company', ['company' => $row['outside_company']]) }}</span>
                                @endif
                                @if ($mayEdit)
                                    <button type="button" wire:click="openAssign({{ $leg->id }})"
                                            class="ms-2 text-xs font-medium text-primary-700 hover:underline">{{ __('Change') }}</button>
                                @endif
                                @if ($type !== '')
                                    <span class="block text-[11px] text-chrome-600"
                                          title="{{ __('The car type asked for') }}">{{ $type }}</span>
                                @endif
                            @else
                                @if ($type !== '')
                                    <span class="block text-chrome-900" title="{{ __('The car type asked for') }}">{{ $type }}</span>
                                @endif
                                @if ($mayEdit)
                                    <button type="button" wire:click="openAssign({{ $leg->id }})"
                                            class="{{ $type !== '' ? 'mt-1 ' : '' }}rounded-lg border border-chrome-200 px-2.5 py-1 text-xs font-medium text-chrome-700 transition hover:bg-chrome-50">
                                        {{ __('Assign car') }}
                                    </button>
                                @elseif ($type === '')
                                    <span class="text-chrome-400">—</span>
                                @endif
                            @endif
                            {{-- The customer is promised the driver and car 12 hours
                                 before pick-up. In this cell (not the driver's) so it
                                 shows on a phone, where the driver column is hidden. --}}
                            @php $urgency = $leg->crewUrgency($now); @endphp
                            @if ($urgency === 'due')
                                <span class="mt-1 block w-fit rounded bg-red-100 px-1.5 py-0.5 text-[11px] font-semibold text-red-700" data-crew-due>
                                    {{ __('Customer waiting — assign car & driver now') }}
                                </span>
                            @elseif ($urgency === 'soon')
                                <span class="mt-1 block w-fit rounded bg-amber-100 px-1.5 py-0.5 text-[11px] font-semibold text-amber-700" data-crew-soon>
                                    {{ __('Assign car & driver before :time', ['time' => $leg->start_at?->copy()->subHours(\Modules\Limousine\Models\LimoLeg::CREW_PROMISE_HOURS)->format('D H:i')]) }}
                                </span>
                            @endif
                            @if ($leg->crew_notified_at !== null)
                                <span class="mt-1 block text-[11px] text-emerald-700" data-crew-sent>
                                    ✓ {{ __('Driver details sent :time', ['time' => $leg->crew_notified_at->format('d-M H:i')]) }}
                                </span>
                            @endif
                        </td>
                        {{-- Driver is set in the same modal as the car; both are
                             per leg, since each leg is dispatched on its own. --}}
                        <td class="px-2 py-2">
                            @if ($row['driver'] !== '')
                                <span class="text-chrome-900">{{ $row['driver'] }}</span>
                            @elseif ($mayEdit)
                                <button type="button" wire:click="openAssign({{ $leg->id }})"
                                        class="rounded-lg border border-chrome-200 px-2.5 py-1 text-xs font-medium text-chrome-700 transition hover:bg-chrome-50">
                                    {{ __('Assign driver') }}
                                </button>
                            @else
                                <span class="text-chrome-400">—</span>
                            @endif
                        </td>
                        <td class="hidden px-2 py-2 text-chrome-900 sm:table-cell" data-added-by-cell>
                            <div>{{ $row['added_by'] ?: '—' }}</div>
                            {{-- "27-Jan-26 21:08" read as the date, then the time under it. --}}
                            @php [$bookedDate, $bookedClock] = array_pad(explode(' ', (string) $row['booked_time'], 2), 2, ''); @endphp
                            <div class="whitespace-nowrap text-[11px] leading-tight text-chrome-500" data-booked-time>
                                <div>{{ $bookedDate !== '' ? $bookedDate : '—' }}</div>
                                @if ($bookedClock !== '')
                                    <div>{{ $bookedClock }}</div>
                                @endif
                            </div>
                        </td>
                        <td class="hidden max-w-[16rem] px-2 py-2 text-chrome-900 lg:table-cell">{{ $row['comments'] ?: '—' }}</td>
                        <td class="px-2 py-2">
                            <span class="whitespace-nowrap rounded px-2 py-0.5 text-[11px] font-semibold uppercase {{ \Modules\Limousine\Models\LimoBooking::paymentBadgeClass($row['payment']) }}" data-payment-state="{{ $row['payment'] }}">{{ \Modules\Limousine\Models\LimoBooking::paymentLabel($row['payment']) }}</span>
                            {{-- Taking money is a booking-level act, so it is offered
                                 from any of its trips and settles all of them. --}}
                            @if ($canAssign && $row['payment'] !== 'paid' && $row['balance'] > 0)
                                <button type="button" wire:click="openCollect({{ $leg->id }})"
                                        title="{{ __('Receive payment for this booking') }}"
                                        aria-label="{{ __('Receive payment for this booking') }}"
                                        class="ms-1 inline-flex size-6 items-center justify-center rounded-lg text-emerald-600 transition hover:bg-emerald-50">
                                    <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
                                    </svg>
                                </button>
                            @endif
                        </td>
                        <td class="hidden px-2 py-2 md:table-cell">
                            @php
                                // One icon per step, so the row shows what it can DO
                                // next rather than spelling it out in words. Each keeps
                                // its label as a tooltip and an aria-label — dropping
                                // the text must not drop the meaning.
                                $stepIcon = [
                                    'confirmed' => 'M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
                                    'active' => 'M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.347a1.125 1.125 0 0 1 0 1.972l-11.54 6.347a1.125 1.125 0 0 1-1.667-.986V5.653Z',
                                    'completed' => 'm4.5 12.75 6 6 9-13.5',
                                ];
                            @endphp
                            <div class="flex items-center gap-1">
                                <span class="rounded px-2 py-0.5 text-[11px] font-semibold uppercase {{ $sb }}">{{ __(ucfirst($row['status'])) }}</span>

                                @if ($next !== null && $canAssign)
                                    <button type="button" wire:click="advanceLeg({{ $leg->id }}, '{{ $next[0] }}')"
                                            title="{{ $next[1] }}" aria-label="{{ $next[1] }}"
                                            class="inline-flex size-7 shrink-0 items-center justify-center rounded-lg text-primary-700 transition hover:bg-primary-50">
                                        <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="{{ $stepIcon[$next[0]] ?? $stepIcon['completed'] }}"/>
                                        </svg>
                                    </button>
                                @endif

                                {{-- Cancel stays available while a trip is still ahead of
                                     or on the road. A finished or already-cancelled trip
                                     has nothing to call off. --}}
                                @if ($canAssign && ! in_array($row['status'], ['completed', 'cancelled'], true))
                                    <button type="button" wire:click="openCancel({{ $leg->id }})"
                                            title="{{ __('Cancel trip') }}" aria-label="{{ __('Cancel trip') }}"
                                            class="inline-flex size-7 shrink-0 items-center justify-center rounded-lg text-red-600 transition hover:bg-red-50">
                                        <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.75 9.75l4.5 4.5m0-4.5-4.5 4.5M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
                                        </svg>
                                    </button>
                                @endif

                                {{-- A trip cancelled by mistake goes back to the queue. --}}
                                @if ($canAssign && $row['status'] === 'cancelled')
                                    <button type="button" wire:click="restoreLeg({{ $leg->id }})"
                                            wire:confirm="{{ __('Put this trip back in the queue?') }}"
                                            title="{{ __('Restore to queue') }}" aria-label="{{ __('Restore to queue') }}"
                                            data-restore-leg="{{ $leg->id }}"
                                            class="inline-flex size-7 shrink-0 items-center justify-center rounded-lg text-primary-700 transition hover:bg-primary-50">
                                        <svg class="size-4 rtl:-scale-x-100" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3"/>
                                        </svg>
                                    </button>
                                @endif
                            </div>
                            @if ($row['status'] === 'cancelled' && $leg->refund_outcome)
                                {{-- What the customer got back, so a cancelled row
                                     isn't a dead end for the person reading it. --}}
                                <span class="ms-2 text-[11px] text-chrome-700">
                                    @if ($leg->refund_outcome === 'coupon')
                                        {{ __('Coupon') }} {{ $money((float) $leg->refund_amount) }}
                                    @elseif ($leg->refund_outcome === 'refunded')
                                        {{ __('Refund due') }} {{ $money((float) $leg->refund_amount) }}
                                    @else
                                        {{ __('No refund') }}
                                    @endif
                                </span>
                            @endif
                        </td>
                        {{-- One 3-dot menu per row instead of a row of icons — five
                             possible actions made the column wide and forced a
                             sideways scroll just to reach it on a phone. Same
                             isolated-per-row Alpine scope + @click.outside as the
                             app-bar dropdowns, and the SAME `fixed`-at-viewport-
                             coords trick: this table sits in an `overflow-x-auto`
                             wrapper, which (per the CSS overflow spec) also clips
                             vertical overflow, so an `absolute` panel opened from a
                             row near the bottom would be cut off. `rowActionsMenu`
                             (resources/js/app.js) additionally flips the panel to
                             open ABOVE the trigger when there is no room below —
                             without it, a row near the bottom of the screen opened
                             a panel whose lower actions were cropped off-screen and
                             unreachable by touch or by mouse. --}}
                        <td class="sticky end-0 z-10 bg-white px-2 py-2 text-end shadow-[-8px_0_8px_-8px_rgba(0,0,0,0.12)] group-hover:bg-chrome-50">
                            @php
                                $item = 'flex w-full items-center gap-2 px-3 py-1.5 text-start text-xs font-medium text-chrome-800 hover:bg-chrome-50';
                                $icon = 'size-4 shrink-0 text-chrome-400';
                                // On a phone the Vehicle and Driver columns' own buttons are
                                // off the screen or a sideways scroll away, so dispatching a
                                // trip has to live in this menu — and that is the job done
                                // standing up.
                                $itemPhone = str_replace('flex w-full', 'flex w-full sm:hidden', $item);
                            @endphp
                            <div x-data="rowActionsMenu" @click.outside="open = false"
                                 @keydown.escape.window="open = false" class="relative">
                                <button type="button" @click="toggle($event.currentTarget)"
                                    class="inline-flex size-7 items-center justify-center rounded-lg text-chrome-700 transition hover:bg-chrome-100"
                                    :aria-expanded="open" aria-haspopup="true"
                                    title="{{ __('Actions') }}" aria-label="{{ __('Actions') }}">
                                    <svg class="size-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                        <path d="M10 3a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3Zm0 5.5a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3Zm0 5.5a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3Z"/>
                                    </svg>
                                </button>

                                {{-- Teleported to <body>: on an iPhone the sticky Actions cell clipped the
                                     fixed panel to the table and slid it under the sticky header. --}}
                                <template x-teleport="body">
                                <div x-ref="panel" x-show="open" x-cloak x-transition.opacity.duration.100ms
                                     :style="(coords.top === null ? 'bottom:' + coords.bottom + 'px;' : 'top:' + coords.top + 'px;') + (coords.rtl ? 'right:' + coords.right + 'px' : 'left:' + coords.left + 'px')"
                                     class="fixed z-50 max-h-[70vh] w-64 overflow-y-auto overflow-x-hidden rounded-lg border border-chrome-200 bg-white py-1 shadow-pop">
                                    <a href="{{ url('/app/limousine/booking/' . $leg->legable_id) }}" wire:navigate
                                       @click="open = false" class="{{ $item }}">
                                        <svg class="{{ $icon }}" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/>
                                        </svg>
                                        {{ __('Open full booking') }}
                                    </a>

                                    @if ($mayEdit)
                                        {{-- Phone only: the car and driver buttons in their own
                                             columns are out of reach at this width. --}}
                                        <button type="button" @click="open = false"
                                                wire:click="openAssign({{ $leg->id }})"
                                                class="{{ $itemPhone }}">
                                            <svg class="{{ $icon }}" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z"/>
                                            </svg>
                                            {{ __('Assign car & driver') }}
                                        </button>
                                        {{-- No "Edit booking details" here: "Open full booking"
                                             above edits the same things (the owner's call). --}}
                                    @endif

                                    {{-- What the driver recorded on his trip link. --}}
                                    <a href="{{ url('/app/limousine/trip-record/' . $leg->id) }}" wire:navigate
                                       @click="open = false" class="{{ $item }}" data-trip-record>
                                        <svg class="{{ $icon }}" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z"/>
                                        </svg>
                                        {{ __('Trip record (KM & location)') }}
                                    </a>
                                    @if ($mayEdit && in_array((int) $leg->id, $runningTrips, true))
                                        <a href="{{ url('/app/limousine/trip-record/' . $leg->id) }}#end" wire:navigate
                                           @click="open = false" class="{{ $item }} text-red-600" data-office-end-trip>
                                            <svg class="{{ $icon }}" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M5.25 7.5A2.25 2.25 0 0 1 7.5 5.25h9a2.25 2.25 0 0 1 2.25 2.25v9a2.25 2.25 0 0 1-2.25 2.25h-9a2.25 2.25 0 0 1-2.25-2.25v-9Z"/>
                                            </svg>
                                            {{ __('End trip for the driver') }}
                                        </a>
                                    @endif

                                    {{-- Service Order PDF — the per-trip sheet. --}}
                                    <a href="{{ url('/app/limousine/service-order/' . $leg->id) }}" target="_blank" rel="noopener"
                                       @click="open = false" class="{{ $item }}">
                                        <svg class="{{ $icon }}" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5A3.375 3.375 0 0 0 10.125 2.25H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/>
                                        </svg>
                                        {{ __('Service order (PDF)') }}
                                    </a>

                                    {{-- The booking's invoice and its receipts (newest first),
                                         each opening its own page. --}}
                                    @php $docs = $paperwork[$leg->legable_id] ?? ['invoice' => null, 'receipts' => []]; @endphp
                                    @if ($docs['invoice'])
                                        <a href="{{ url('/app/limousine/invoice/' . $docs['invoice']['id']) }}" wire:navigate
                                           @click="open = false" class="{{ $item }}" data-open-invoice>
                                            <svg class="{{ $icon }}" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 14.25h6m-6-4.5h6M7.5 3h9A1.5 1.5 0 0 1 18 4.5v16.5l-3-1.5-3 1.5-3-1.5-3 1.5V4.5A1.5 1.5 0 0 1 7.5 3Z"/>
                                            </svg>
                                            <span class="min-w-0 truncate">{{ __('Open invoice') }} <span class="text-chrome-400">{{ $docs['invoice']['reference'] }}</span></span>
                                        </a>
                                    @endif
                                    @foreach (array_slice($docs['receipts'], 0, 5) as $receipt)
                                        {{-- The receipt page is for repairs (owner + Accountant,
                                             ReceiptForm::guardManualCreate()); everyone else gets
                                             the receipt itself, as a PDF. --}}
                                        @php $repairsReceipts ??= (bool) auth()->user()?->canConfirmPayments(); @endphp
                                        <a href="{{ url('/app/limousine/receipt/' . $receipt['id'] . ($repairsReceipts ? '' : '/download')) }}" {{ $repairsReceipts ? 'wire:navigate' : '' }}
                                           @click="open = false" class="{{ $item }}" data-open-receipt>
                                            <svg class="{{ $icon }}" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm3 0h.008v.008H18V10.5Zm-12 0h.008v.008H6V10.5Z"/>
                                            </svg>
                                            <span class="min-w-0 truncate">{{ __('Open receipt') }} <span class="text-chrome-400">{{ $receipt['reference'] }} · {{ \App\Erp\Money\Currencies::format($receipt['amount']) }}</span></span>
                                        </a>
                                    @endforeach

                                    {{-- Online payment link (Wanaan website → Tap). Only when the portal is on. --}}
                                    @if ($portalOn)
                                        <button type="button" @click="open = false" wire:click="openPaymentLink({{ $leg->id }})"
                                                class="{{ $item }}">
                                            <svg class="{{ $icon }}" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3M3.75 19.5h16.5A2.25 2.25 0 0 0 22.5 17.25V6.75A2.25 2.25 0 0 0 20.25 4.5H3.75A2.25 2.25 0 0 0 1.5 6.75v10.5A2.25 2.25 0 0 0 3.75 19.5Z"/>
                                            </svg>
                                            {{ __('Create payment link') }}
                                        </button>
                                    @endif

                                    @if ($canAssign)
                                        @php $canSign = $signable[$leg->id] ?? true; @endphp
                                        <div class="my-1 border-t border-chrome-100"></div>
                                        @if ($leg->isSigned())
                                            {{-- Signed: the proof exists, nothing left to send. --}}
                                            <div class="flex items-center gap-2 px-3 py-1.5 text-xs font-medium text-emerald-600"
                                                 title="{{ __('Signed by') }} {{ $leg->signed_name }} · {{ $leg->signed_at?->isoFormat('DD-MMM-YY HH:mm') }}">
                                                <svg class="size-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" aria-hidden="true">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
                                                </svg>
                                                {{ __('Signed') }}
                                            </div>
                                        @elseif ($leg->service_order_sent_at)
                                            {{-- Sent: a green tick says so, and Resend covers a
                                                 bounced mail or lapsed link. --}}
                                            <div class="flex items-center gap-2 px-3 py-1.5 text-xs font-medium text-emerald-600"
                                                 title="{{ $leg->service_order_sent_at->isoFormat('DD-MMM-YY HH:mm') }}">
                                                <svg class="size-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" aria-hidden="true">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/>
                                                </svg>
                                                {{ $canSign ? __('Sent to sign') : __('Company notified') }}
                                            </div>
                                            <button type="button" @click="open = false" wire:click="sendServiceOrder({{ $leg->id }})"
                                                    wire:confirm="{{ __('Send this again?') }}"
                                                    class="{{ $item }}">
                                                <svg class="{{ $icon }}" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992V4.356m-.001 0-3.181 3.183a8.25 8.25 0 0 0-13.803 3.7M4.031 9.865v4.992m0 0h4.992m-4.993 0 3.181-3.183a8.25 8.25 0 0 0 13.803-3.7"/>
                                                </svg>
                                                {{ __('Resend') }}
                                            </button>
                                        @else
                                            {{-- A company books for its guest, so it is told the
                                                 driver arrived rather than asked to sign for a
                                                 trip it was not on. --}}
                                            <button type="button" @click="open = false" wire:click="sendServiceOrder({{ $leg->id }})"
                                                    wire:confirm="{{ $canSign
                                                        ? __('Email the customer a link to sign this service order?')
                                                        : __('Email the company that the driver has reached their customer?') }}"
                                                    class="{{ $item }}">
                                                <svg class="{{ $icon }}" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5"/>
                                                </svg>
                                                {{ $canSign ? __('Send to sign') : __('Notify company') }}
                                            </button>
                                        @endif
                                    @endif
                                </div>
                                </template>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="{{ 18 + ($showEta ? 1 : 0) }}" class="px-4 py-10 text-center text-sm text-chrome-600">
                        {{ __('No bookings found.') }}
                        @if ($elsewhere > 0)
                            <div class="mt-2" data-search-elsewhere>
                                {{ trans_choice(':count matching trip is on another tab.|:count matching trips are on other tabs.', $elsewhere, ['count' => $elsewhere]) }}
                                <button type="button" wire:click="openTab('all')" class="ms-1 font-semibold text-primary-700 hover:underline">{{ __('Search all tabs') }}</button>
                            </div>
                        @endif
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $legs->links('vendor.pagination.compact') }}</div>

    {{-- ── Assign car ──
         ONE leg, one picker. Legs run at different times on different days, so
         each is dispatched on its own — grouping them would make the office
         decide a trip it isn't sending yet. All they share is a receipt. --}}
    @if ($assigningLeg !== null)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-chrome-900/50 p-4"
             wire:key="assign-{{ $assigningLeg->id }}"
             x-on:keydown.escape.window="$wire.closeAssign()">
            <div class="w-full max-w-md rounded-2xl bg-white p-5 shadow-xl sm:p-6" x-on:click.outside="$wire.closeAssign()">
                <h2 class="text-sm font-semibold text-chrome-800">{{ __('Assign car & driver') }}</h2>
                <p class="mt-1 text-xs text-chrome-500">
                    {{ $assigningLeg->reference }}
                    @if ($assigningLeg->legable?->customer?->name) · {{ $assigningLeg->legable->customer->name }} @endif
                </p>
                <p class="mt-0.5 text-xs text-chrome-500">
                    {{-- Held back until the crew is named: just the countries. --}}
                    @if (\Modules\Limousine\Support\TripDetailsLock::hides($assigningLeg))
                        {{ \Modules\Limousine\Support\TripDetailsLock::place($assigningLeg->from_country) }}{{ $assigningLeg->to_location ? ' → ' . \Modules\Limousine\Support\TripDetailsLock::place($assigningLeg->to_country) : '' }}
                    @else
                        {{ $assigningLeg->from_location ?? '—' }}{{ $assigningLeg->to_location ? ' → ' . $assigningLeg->to_location : '' }}
                    @endif
                    @if ($assigningLeg->start_at) · {{ $assigningLeg->start_at->isoFormat('DD-MMM-YY HH:mm') }} @endif
                </p>

                {{-- Opened by pressing Start trip: say that the press is still
                     going to happen, so naming the two does not feel like a
                     detour from what was actually asked for. --}}
                @if ($startAfterAssign)
                    <p class="mt-3 rounded-lg bg-primary-50 px-3 py-2 text-xs text-primary-800">
                        {{ __('The trip starts as soon as a car and a driver are named.') }}
                    </p>
                @endif

                <div class="mt-4 space-y-4">
                    {{-- Whose car: one of ours from the fleet, or another
                         company's car that one of our drivers drives. --}}
                    <div>
                        <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Car') }}</label>
                        <div class="mb-2 grid grid-cols-2 gap-1 rounded-lg bg-chrome-100 p-1" role="radiogroup" data-car-source>
                            @foreach (['own' => __('Our car'), 'outside' => __("Another company's car")] as $source => $sourceLabel)
                                <button type="button" role="radio" aria-checked="{{ $assignCarSource === $source ? 'true' : 'false' }}"
                                        wire:click="$set('assignCarSource', '{{ $source }}')"
                                        class="rounded-md px-2 py-1.5 text-sm font-medium transition {{ $assignCarSource === $source ? 'bg-white text-chrome-900 shadow-sm' : 'text-chrome-600 hover:text-chrome-900' }}">
                                    {{ $sourceLabel }}
                                </button>
                            @endforeach
                        </div>

                        @if ($assignCarSource === 'outside')
                            <div class="space-y-3 rounded-lg border border-chrome-200 p-3" wire:key="assign-outside" data-outside-car>
                                <div>
                                    <label class="mb-1 block text-xs font-medium text-chrome-600">{{ __('Company') }}</label>
                                    <input type="text" wire:model="assignOutsideCompany" maxlength="150" class="o-input w-full" placeholder="{{ __('Whose car it is') }}">
                                    @error('assignOutsideCompany') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-medium text-chrome-600">{{ __('Car, colour and plate') }}</label>
                                    <input type="text" wire:model="assignOutsideCar" maxlength="150" class="o-input w-full" placeholder="{{ __('e.g. GMC Yukon, black, 45213') }}">
                                    @error('assignOutsideCar') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-medium text-chrome-600">{{ __('What we pay them (BD)') }} <span class="font-normal text-chrome-500">({{ __('optional') }})</span></label>
                                    <input type="number" step="any" min="0" inputmode="decimal" wire:model="assignOutsideCost" placeholder="0" class="o-input w-full">
                                    @error('assignOutsideCost') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                </div>
                            </div>
                            @error('assignCar') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        @else
                            <div wire:key="assign-own">
                                <x-searchable-select wire:model.live="assignCar" class="o-input w-full"
                                    :options="$carOptions"
                                    :empty="__('No cars available')"
                                    :search-placeholder="__('Search plate or model…')" />
                                @error('assignCar') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>
                        @endif
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Driver') }}</label>
                        <x-searchable-select wire:model.live="assignDriver" class="o-input w-full"
                            :options="$driverOptions"
                            :empty="__('No drivers available')"
                            :search-placeholder="__('Search driver…')" />
                        @error('assignDriver') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        {{-- Same people drive for both apps, so the list is shared. --}}
                        <p class="mt-1 text-xs text-chrome-500">
                            <a href="{{ url('/app/limousine/driver') }}" class="text-primary-700 hover:underline">{{ __('Manage drivers') }}</a>
                        </p>
                        {{-- A trip that leaves Bahrain: say now if this driver
                             holds no travel authorization for this car. A
                             warning only; the assignment can still be saved. --}}
                        @if ($permitWarning !== null)
                            <div class="mt-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-800" role="alert" data-permit-warning>
                                <p class="flex items-start gap-1.5 font-semibold">
                                    <svg class="mt-0.5 size-4 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495ZM10 5a.75.75 0 0 1 .75.75v3.5a.75.75 0 0 1-1.5 0v-3.5A.75.75 0 0 1 10 5Zm0 9a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" clip-rule="evenodd"/></svg>
                                    <span>{{ $permitWarning['message'] }}</span>
                                </p>
                                <a href="{{ url('/app/rental/travel-permit') }}" target="_blank" rel="noopener" class="mt-1 inline-block ps-5 font-semibold underline">{{ __('Make a travel authorization') }}</a>
                            </div>
                        @endif
                    </div>

                    {{-- The driver's commission for this trip, always offered and
                         optional. Picking a commission driver fills in their usual
                         amount; picking anyone else empties it, so one driver's
                         figure never rides over to the next. An amount typed for a
                         company driver makes them a commission driver on Save. The
                         picker changes in the browser alone until Save, so this
                         follows it in the browser too. --}}
                    <div x-data="{ rates: @js($commissionDrivers), last: @js($assignDriver) }"
                         x-effect="const id = String($wire.assignDriver ?? ''); if (id !== last) { last = id; $wire.assignDriverPay = Object.hasOwn(rates, id) ? rates[id] : '' }"
                         data-driver-pay>
                        <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Commission (BD)') }} <span class="font-normal text-chrome-500">({{ __('optional') }})</span></label>
                        <input type="number" step="any" min="0" inputmode="decimal" wire:model="assignDriverPay"
                               placeholder="0" class="o-input w-full">
                        <p class="mt-1 text-xs text-chrome-500">{{ __('What the driver is paid for this trip. An amount here makes them a commission driver.') }}</p>
                        @error('assignDriverPay') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" wire:click="closeAssign" class="o-btn-ghost text-sm">{{ __('Close') }}</button>
                    <button type="button" wire:click="saveAssign" wire:loading.attr="disabled" class="o-btn-primary text-sm">
                        {{ $startAfterAssign ? __('Start trip') : __('Save') }}
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- ── Cancel a trip ──
         The consequence is shown BEFORE the button is pressed, because the three
         outcomes differ in money: nothing paid cancels cleanly; paid with more
         than 48 hours to go earns a full refund; paid inside 48 hours earns no
         refund but a coupon for what was paid. --}}
    @if ($cancellingLeg && $cancelPreview)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-chrome-900/40 p-4" wire:key="cancel-{{ $cancellingLeg->id }}">
            <div class="flex min-h-full items-start justify-center py-10">
                <div class="w-full max-w-md rounded-2xl bg-white shadow-pop">
                    <div class="flex items-center justify-between border-b border-chrome-200 px-5 py-3">
                        <h2 class="text-base font-bold text-chrome-900">
                            {{ __('Cancel trip') }} — {{ $cancellingLeg->reference }}
                        </h2>
                        <button type="button" wire:click="closeCancel"
                                class="text-lg leading-none text-chrome-400 hover:text-chrome-700" aria-label="{{ __('Close') }}">&times;</button>
                    </div>

                    <div class="px-5 py-4">
                        @php
                            $hrs = $cancelPreview['hours_to_start'];
                            $amt = $money((float) $cancelPreview['amount']);
                        @endphp

                        <p class="text-sm text-chrome-600">
                            @if (\Modules\Limousine\Support\TripDetailsLock::hides($cancellingLeg))
                                {{ \Modules\Limousine\Support\TripDetailsLock::place($cancellingLeg->from_country) }}
                                @if ($cancellingLeg->to_location) → {{ \Modules\Limousine\Support\TripDetailsLock::place($cancellingLeg->to_country) }} @endif
                            @else
                                {{ $cancellingLeg->from_location }}
                                @if ($cancellingLeg->to_location) → {{ $cancellingLeg->to_location }} @endif
                            @endif
                            @if ($cancellingLeg->start_at)
                                <span class="block text-xs text-chrome-400">{{ $cancellingLeg->start_at->isoFormat('DD-MMM-YY hh:mm A') }}</span>
                            @endif
                        </p>

                        @if (! $cancelPreview['paid'])
                            <div class="mt-4 rounded-lg bg-chrome-50 px-4 py-3 text-sm text-chrome-700 ring-1 ring-chrome-200">
                                {{ __('Nothing has been paid for this trip, so there is nothing to refund.') }}
                            </div>
                        @elseif ($cancelPreview['refund_due'])
                            <div class="mt-4 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-800 ring-1 ring-emerald-200">
                                <p class="font-semibold">{{ __('Full refund due') }}: {{ $amt }}</p>
                                <p class="mt-0.5 text-xs">
                                    {{ __('Cancelled more than :hours hours before the trip.', ['hours' => 48]) }}
                                    @if ($hrs !== null) ({{ __(':hours hours to go', ['hours' => number_format((float) $hrs, 1)]) }}) @endif
                                </p>
                                @if ($cancelPreview['coupons'])
                                    <label class="mt-2 flex items-center gap-2 text-xs font-medium">
                                        <input type="checkbox" wire:model="cancelAsCoupon"
                                               class="size-4 rounded border-chrome-300 text-primary-600 focus:ring-primary-500">
                                        {{ __('Give it as a coupon instead of refunding the money') }}
                                    </label>
                                @endif
                            </div>
                        @elseif (! $cancelPreview['coupons'])
                            <div class="mt-4 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900 ring-1 ring-amber-200">
                                <p class="font-semibold">{{ __('No refund') }} — {{ __('the payment of :amount is kept', ['amount' => $amt]) }}</p>
                                <p class="mt-0.5 text-xs">
                                    {{ __('Cancelled within :hours hours of the trip. The coupon rule is switched off, so no coupon is issued.', ['hours' => 48]) }}
                                </p>
                            </div>
                        @else
                            <div class="mt-4 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900 ring-1 ring-amber-200">
                                <p class="font-semibold">{{ __('No refund') }} — {{ __('a coupon for :amount will be issued', ['amount' => $amt]) }}</p>
                                <p class="mt-0.5 text-xs">
                                    {{ __('Cancelled within :hours hours of the trip. The customer keeps the value as credit, valid one year from the booking.', ['hours' => 48]) }}
                                </p>
                            </div>
                        @endif

                        <label class="mt-4 block text-xs font-medium text-chrome-600">{{ __('Reason (optional)') }}</label>
                        <input type="text" wire:model="cancelReason" class="o-input mt-1 w-full"
                               placeholder="{{ __('e.g. customer changed plans') }}">
                    </div>

                    <div class="flex items-center justify-end gap-2 border-t border-chrome-200 px-5 py-3">
                        <button type="button" wire:click="closeCancel" class="o-btn-ghost text-sm">{{ __('Keep trip') }}</button>
                        <button type="button" wire:click="confirmCancel" wire:loading.attr="disabled"
                                class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-red-700">
                            {{ __('Cancel trip') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- ── Update booking details ──
         Booking-level fields, so one dialog serves every leg of the job. Fixing
         a passenger name or a flight number is a five-second correction that
         shouldn't mean leaving the queue.

         The fare is absent on purpose: it is the sum of the legs
         (LimoBooking::recalcTotal), so a figure typed here would be wiped the
         next time a leg changes. It shows read-only, with a link to the full
         booking where legs and pricing live. --}}
    @if ($editing)
        @php
            $lbl = 'block text-xs font-medium text-chrome-600';
            $err = 'mt-1 text-xs text-red-600';
        @endphp
        <div class="fixed inset-0 z-50 overflow-y-auto bg-chrome-900/40 p-4" wire:key="edit-{{ $editing->id }}">
            <div class="flex min-h-full items-start justify-center py-8">
                <div class="w-full max-w-2xl rounded-2xl bg-white shadow-pop">
                    <div class="flex items-center justify-between border-b border-chrome-200 px-5 py-3">
                        <h2 class="text-base font-bold text-chrome-900">
                            @if ($editingLeg)
                                {{ __('Edit trip') }} {{ $editingLeg->reference }}
                            @else
                                {{ __('Update booking details') }} — {{ $editing->reference }}
                            @endif
                        </h2>
                        <button type="button" wire:click="cancelEdit"
                                class="text-lg leading-none text-chrome-400 hover:text-chrome-700" aria-label="{{ __('Close') }}">&times;</button>
                    </div>

                    <div class="max-h-[70vh] overflow-y-auto px-5 py-4">
                        <div>
                            <label class="{{ $lbl }}">{{ __('Customer') }}</label>
                            <input type="text" value="{{ $editing->customer?->name ?? '—' }}" disabled
                                   class="o-input mt-1 w-full bg-chrome-100 text-chrome-600">
                        </div>

                        {{-- THIS trip only. A booking can hold several legs, and the
                             office clicked one row — so its route, time and price are
                             edited here, on their own, and the other legs are left
                             alone. The booking-wide fields follow, under their own
                             heading, so it is never a guess which is which. --}}
                        @if ($editingLeg && $editLeg)
                            @php $legIsChauffeur = ($editLeg['service_type'] ?? 'transfer') === 'chauffeur'; @endphp
                            <div class="mt-4 rounded-xl border border-primary-200 bg-primary-50/40 p-4">
                                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                                    <h3 class="text-xs font-semibold uppercase tracking-wide text-primary-800">
                                        {{ __('This trip') }} · {{ $editingLeg->reference }}
                                    </h3>
                                    <div class="flex gap-1">
                                        @foreach ($serviceTypes as $opt)
                                            <button type="button"
                                                wire:click="$set('editLeg.service_type', '{{ $opt['value'] }}')"
                                                class="rounded-lg border px-2.5 py-1 text-xs transition {{ ($editLeg['service_type'] ?? '') === $opt['value'] ? 'border-primary-500 bg-white font-medium text-primary-700' : 'border-chrome-200 text-chrome-600 hover:bg-white' }}">
                                                {{ __($opt['label']) }}
                                            </button>
                                        @endforeach
                                    </div>
                                </div>

                                <div class="grid gap-3 sm:grid-cols-2">
                                    <div>
                                        <label class="{{ $lbl }}">{{ __('Pickup') }} *</label>
                                        <input type="text" wire:model="editLeg.from_location" class="o-input mt-1 w-full">
                                        @error('editLeg.from_location') <p class="{{ $err }}">{{ $message }}</p> @enderror
                                        <input type="url" wire:model="editLeg.from_location_url" class="o-input mt-1 w-full text-xs"
                                               placeholder="{{ __('Pick-up map link (optional)') }}">
                                        @error('editLeg.from_location_url') <p class="{{ $err }}">{{ $message }}</p> @enderror
                                    </div>

                                    @if ($legIsChauffeur)
                                        <div>
                                            <label class="{{ $lbl }}">{{ __('Start date & time') }} *</label>
                                            <x-date-field type="datetime-local" wire:model="editLeg.start_at" class="o-input mt-1 w-full" />
                                            @error('editLeg.start_at') <p class="{{ $err }}">{{ $message }}</p> @enderror
                                        </div>
                                        <div>
                                            <label class="{{ $lbl }}">{{ __('Hours per day') }} *</label>
                                            <input type="number" step="0.5" min="0" wire:model.live="editLeg.hours" class="o-input mt-1 w-full">
                                            @error('editLeg.hours') <p class="{{ $err }}">{{ $message }}</p> @enderror
                                        </div>
                                        <div>
                                            <label class="{{ $lbl }}">{{ __('Number of days') }} *</label>
                                            <input type="number" min="1" wire:model.live="editLeg.days" class="o-input mt-1 w-full">
                                            @error('editLeg.days') <p class="{{ $err }}">{{ $message }}</p> @enderror
                                        </div>
                                    @else
                                        <div>
                                            <label class="{{ $lbl }}">{{ __('Drop off') }} *</label>
                                            <input type="text" wire:model="editLeg.to_location" class="o-input mt-1 w-full">
                                            @error('editLeg.to_location') <p class="{{ $err }}">{{ $message }}</p> @enderror
                                            <input type="url" wire:model="editLeg.to_location_url" class="o-input mt-1 w-full text-xs"
                                                   placeholder="{{ __('Drop-off map link (optional)') }}">
                                            @error('editLeg.to_location_url') <p class="{{ $err }}">{{ $message }}</p> @enderror
                                        </div>
                                        <div>
                                            <label class="{{ $lbl }}">{{ __('Date & time') }} *</label>
                                            <x-date-field type="datetime-local" wire:model="editLeg.start_at" class="o-input mt-1 w-full" />
                                            @error('editLeg.start_at') <p class="{{ $err }}">{{ $message }}</p> @enderror
                                        </div>
                                    @endif

                                    <div>
                                        <label class="{{ $lbl }}">{{ __('Rate (BHD)') }} *</label>
                                        <input type="number" step="0.001" min="0" wire:model.live="editLeg.rate" class="o-input mt-1 w-full">
                                        @error('editLeg.rate') <p class="{{ $err }}">{{ $message }}</p> @enderror
                                    </div>
                                    <div>
                                        <label class="{{ $lbl }}">{{ __('Rate basis') }} *</label>
                                        <select wire:model.live="editLeg.rate_basis" class="o-input mt-1 w-full">
                                            @foreach ($rateBasisOptions as $opt)<option value="{{ $opt['value'] }}">{{ __($opt['label']) }}</option>@endforeach
                                        </select>
                                    </div>
                                    {{-- A rate per hour needs the hours, a rate per day the days —
                                         as on the booking page; a chauffeur job asks for both above. --}}
                                    @unless ($legIsChauffeur)
                                        @if (($editLeg['rate_basis'] ?? '') === 'hour')
                                            <div>
                                                <label class="{{ $lbl }}">{{ __('Hours') }} *</label>
                                                <input type="number" step="0.5" min="0.5" wire:model.live="editLeg.hours" class="o-input mt-1 w-full">
                                                @error('editLeg.hours') <p class="{{ $err }}">{{ $message }}</p> @enderror
                                            </div>
                                        @elseif (($editLeg['rate_basis'] ?? '') === 'day')
                                            <div>
                                                <label class="{{ $lbl }}">{{ __('Number of days') }} *</label>
                                                <input type="number" min="1" wire:model.live="editLeg.days" class="o-input mt-1 w-full">
                                                @error('editLeg.days') <p class="{{ $err }}">{{ $message }}</p> @enderror
                                            </div>
                                        @endif
                                    @endunless
                                    <div>
                                        <label class="{{ $lbl }}">{{ __('Discount (BHD)') }}</label>
                                        <input type="number" step="0.001" min="0" wire:model.live="editLeg.discount" class="o-input mt-1 w-full">
                                    </div>
                                    <div>
                                        <label class="{{ $lbl }}">{{ __('VAT (BHD)') }}</label>
                                        <input type="number" step="0.001" min="0" wire:model.live="editLeg.vat" class="o-input mt-1 w-full">
                                    </div>
                                </div>

                                <p class="mt-3 text-xs text-primary-800">
                                    {{ __('This trip:') }}
                                    <span class="font-semibold">{{ \App\Erp\Views\ValueFormat::money($this->editLegTotal()) }}</span>
                                    <span class="text-chrome-500">· {{ __('changes only this leg; the booking total follows.') }}</span>
                                </p>
                            </div>

                            <h3 class="mt-5 text-xs font-semibold uppercase tracking-wide text-chrome-500">
                                {{ __('Booking details') }} · {{ __('shared by every trip on :reference', ['reference' => $editing->reference]) }}
                            </h3>
                        @endif

                        <div class="mt-3 grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="{{ $lbl }}">{{ __('Booking from') }}</label>
                                <x-date-field type="datetime-local" wire:model="edit.pickup_at" class="o-input mt-1 w-full" />
                                @error('edit.pickup_at') <p class="{{ $err }}">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="{{ $lbl }}">{{ __('Booking to') }}</label>
                                <x-date-field type="datetime-local" wire:model="edit.booking_to" class="o-input mt-1 w-full" />
                                @error('edit.booking_to') <p class="{{ $err }}">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label class="{{ $lbl }}">{{ __('Booking type') }}</label>
                                <select wire:model="edit.booking_type" class="o-input mt-1 w-full">
                                    <option value="">{{ __('— Select —') }}</option>
                                    @foreach ($bookingTypes as $opt)
                                        <option value="{{ $opt['value'] }}">{{ __($opt['label']) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="{{ $lbl }}">{{ __('Amount') }}</label>
                                <input type="text" value="{{ \App\Erp\Views\ValueFormat::money($editing->fare) }}" disabled
                                       class="o-input mt-1 w-full bg-chrome-100 text-chrome-600">
                                <p class="mt-1 text-[11px] text-chrome-400">{{ __('The sum of every trip on this booking.') }}</p>
                            </div>

                            <div>
                                <label class="{{ $lbl }}">{{ __('Flight number') }}</label>
                                <input type="text" wire:model="edit.flight_number" class="o-input mt-1 w-full">
                            </div>
                            <div>
                                <label class="{{ $lbl }}">{{ __('Email ID') }}</label>
                                <input type="email" wire:model="edit.email" class="o-input mt-1 w-full">
                                @error('edit.email') <p class="{{ $err }}">{{ $message }}</p> @enderror
                            </div>

                            {{-- Car and driver are shown but LOCKED. They belong to the
                                 leg, not the booking: each trip is dispatched on its own
                                 with its own vehicle and crew, so there is no single
                                 "the car" for a booking to hold. Editing them here would
                                 write a booking-level label that changes nothing about
                                 what is actually dispatched — a field that looks like it
                                 works and doesn't. Assign them from the queue instead. --}}
                            <div>
                                <label class="{{ $lbl }}">{{ __('Car details') }}</label>
                                <input type="text" disabled
                                       value="{{ $editingLeg?->vehicle ?: __('Not assigned') }}"
                                       class="o-input mt-1 w-full bg-chrome-100 text-chrome-600">
                            </div>
                            <div>
                                <label class="{{ $lbl }}">{{ __('Driver') }}</label>
                                <input type="text" disabled
                                       value="{{ $editingLeg?->driver ?: __('Not assigned') }}"
                                       class="o-input mt-1 w-full bg-chrome-100 text-chrome-600">
                            </div>
                            <div class="sm:col-span-2 -mt-1">
                                <p class="text-[11px] text-chrome-400">
                                    🔒 {{ __('Car and driver belong to the trip, not the booking — assign them from the queue (Assign car / Assign driver).') }}
                                </p>
                            </div>

                            <div>
                                <label class="{{ $lbl }}">{{ __('PAX name') }}</label>
                                <input type="text" wire:model="edit.pax_name" class="o-input mt-1 w-full">
                                @error('edit.pax_name') <p class="{{ $err }}">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="{{ $lbl }}">{{ __('PAX contact') }}</label>
                                <input type="text" wire:model="edit.pax_contact" class="o-input mt-1 w-full">
                            </div>

                            <div>
                                <label class="{{ $lbl }}">{{ __('Contact person') }}</label>
                                <input type="text" wire:model="edit.contact_person" class="o-input mt-1 w-full">
                            </div>
                            {{-- Corporate accounts only: a company quotes its own PO /
                                 reference so the trip can be matched on their side. An
                                 individual has nothing to put here, so the field would
                                 just be noise on most bookings. --}}
                            @if ($editing->customer?->isCompany())
                                <div>
                                    <label class="{{ $lbl }}">{{ __('Company reference') }}</label>
                                    <input type="text" wire:model="edit.company_reference" class="o-input mt-1 w-full">
                                </div>
                            @endif

                            <div>
                                <label class="{{ $lbl }}">{{ __('Rate type') }}</label>
                                <select wire:model="edit.rate_type" class="o-input mt-1 w-full">
                                    <option value="">{{ __('— Select —') }}</option>
                                    @foreach ($rateTypes as $opt)
                                        <option value="{{ $opt['value'] }}">{{ __($opt['label']) }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="mt-3">
                            <label class="{{ $lbl }}">{{ __('Comments') }}</label>
                            <textarea wire:model="edit.notes" rows="3" class="o-input mt-1 w-full"></textarea>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center justify-between gap-2 border-t border-chrome-200 px-5 py-3">
                        <a href="{{ url('/app/limousine/booking/' . $editing->id) }}" wire:navigate
                           class="text-xs text-chrome-500 hover:underline">{{ __('Open full booking (all trips)') }}</a>
                        <div class="flex items-center gap-2">
                            <button type="button" wire:click="saveEdit" wire:loading.attr="disabled" class="o-btn-primary text-sm">{{ __('Save changes') }}</button>
                            <button type="button" wire:click="cancelEdit" class="o-btn-ghost text-sm">{{ __('Cancel') }}</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Receiving money.

         Opened from a trip row but addressed to the BOOKING, because that is
         what a customer settles. The trips are listed with their own prices so
         the total is visibly the sum of them: the question this dialog exists
         to answer is "is this one bill or three?", and the answer is one. --}}
    @if ($collecting)
        @php
            $lbl = 'block text-xs font-medium text-chrome-600';
            $err = 'mt-1 text-xs text-red-600';
            $due = $collecting->balanceDue();
            $bd = fn (float $v): string => \App\Erp\Views\ValueFormat::money($v);
        @endphp
        <div class="fixed inset-0 z-50 overflow-y-auto bg-chrome-900/40 p-4" wire:key="collect-{{ $collecting->id }}">
            <div class="flex min-h-full items-start justify-center py-8">
                <div class="w-full max-w-lg rounded-2xl bg-white shadow-pop">
                    <div class="flex items-center justify-between border-b border-chrome-200 px-5 py-3">
                        <h2 class="text-base font-bold text-chrome-900">
                            {{ __('Receive payment') }} — {{ $collecting->reference }}
                        </h2>
                        <button type="button" wire:click="closeCollect"
                                class="text-lg leading-none text-chrome-400 hover:text-chrome-700" aria-label="{{ __('Close') }}">&times;</button>
                    </div>

                    <div class="max-h-[70vh] overflow-y-auto px-5 py-4">
                        <p class="text-sm text-chrome-600">
                            {{ __('One bill for the whole booking') }} —
                            <span class="font-medium text-chrome-800">{{ $collecting->customer?->name ?? '—' }}</span>
                        </p>

                        <div class="mt-3 overflow-hidden rounded-xl ring-1 ring-chrome-200">
                            <table class="w-full text-sm">
                                <tbody class="divide-y divide-chrome-100">
                                    {{-- A cancelled trip is shown but struck through: it
                                         is part of the booking's history and none of the
                                         bill, so the total below visibly excludes it
                                         rather than appearing not to add up. --}}
                                    @foreach ($collecting->legs as $l)
                                        @php $billed = $l->isBillable(); @endphp
                                        <tr class="{{ $billed ? 'text-chrome-700' : 'text-chrome-400' }}">
                                            <td class="px-3 py-2">
                                                <span class="font-medium">{{ $l->reference }}</span>
                                                <span class="ms-1 text-xs text-chrome-500">{{ $l->from_location }}</span>
                                                @if ($l->status === 'cancelled')
                                                    <span class="ms-1 text-[11px] uppercase">
                                                        ({{ $billed ? __('Cancelled — forfeited') : __('Cancelled — not billed') }})
                                                    </span>
                                                @endif
                                            </td>
                                            <td class="px-3 py-2 text-end {{ $billed ? '' : 'line-through' }}">{{ $bd((float) $l->net_amount) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot class="bg-chrome-50 text-sm">
                                    <tr class="font-semibold text-chrome-800">
                                        <td class="px-3 py-2">{{ __('Total') }}</td>
                                        <td class="px-3 py-2 text-end">{{ $bd($collecting->netAmount()) }}</td>
                                    </tr>
                                    <tr class="text-emerald-700">
                                        <td class="px-3 py-2">{{ __('Already received') }}</td>
                                        <td class="px-3 py-2 text-end">{{ $bd((float) $collecting->advance) }}</td>
                                    </tr>
                                    <tr class="font-semibold {{ $due > 0 ? 'text-amber-700' : 'text-chrome-500' }}">
                                        <td class="px-3 py-2">{{ __('Still owed') }}</td>
                                        <td class="px-3 py-2 text-end">{{ $bd($due) }}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>

                        <div class="mt-4 grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="{{ $lbl }}">{{ __('Amount now') }} *</label>
                                <input type="number" step="0.001" min="0" wire:model="collectAmount" class="o-input mt-1 w-full">
                                @error('collectAmount') <p class="{{ $err }}">{{ $message }}</p> @enderror
                                <p class="mt-1 text-[11px] text-chrome-400">{{ __('Type less than the balance to take a part payment.') }}</p>
                            </div>
                            {{-- Under the amount, because the two are one fact:
                                 how much arrived, and when. --}}
                            <div>
                                <label class="{{ $lbl }}">{{ __('Date received') }} *</label>
                                <x-date-field wire:model="collectDate" class="o-input mt-1 w-full" />
                                @error('collectDate') <p class="{{ $err }}">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="{{ $lbl }}">{{ __('Method') }} *</label>
                                <select wire:model="collectMethod" class="o-input mt-1 w-full">
                                    @foreach ($paymentMethods as $opt)<option value="{{ $opt['value'] }}">{{ __($opt['label']) }}</option>@endforeach
                                </select>
                                @error('collectMethod') <p class="{{ $err }}">{{ $message }}</p> @enderror
                            </div>
                            <div class="sm:col-span-2">
                                <label class="{{ $lbl }}">{{ __('Note') }}</label>
                                <input type="text" wire:model="collectNote" class="o-input mt-1 w-full"
                                       placeholder="{{ __('e.g. cheque number, who handed it over') }}">
                                @error('collectNote') <p class="{{ $err }}">{{ $message }}</p> @enderror
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-2 border-t border-chrome-200 px-5 py-3">
                        <button type="button" wire:click="saveCollect" wire:loading.attr="disabled" class="o-btn-primary text-sm">{{ __('Record payment') }}</button>
                        <button type="button" wire:click="closeCollect" class="o-btn-ghost text-sm">{{ __('Cancel') }}</button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Online payment link dialog: choose the partition, generate the link,
         then copy it to the customer. --}}
    @if ($paymentLegId !== null)
        <div class="fixed inset-0 z-40 flex items-center justify-center bg-chrome-900/50 p-4" wire:key="pay-link-modal">
            <div class="w-full max-w-md rounded-2xl bg-white shadow-xl">
                <div class="flex items-center justify-between border-b border-chrome-200 px-5 py-3">
                    <h3 class="text-sm font-semibold text-chrome-900">{{ __('Payment link') }}</h3>
                    <button type="button" wire:click="closePaymentLink" class="text-chrome-400 hover:text-chrome-700" aria-label="{{ __('Close') }}">&times;</button>
                </div>

                <div class="px-5 py-4">
                    @php $covers = $this->paymentLinkCovers(); @endphp
                    @if ($covers !== null)
                        <p class="mb-3 rounded-lg bg-sky-50 px-3 py-2 text-xs text-sky-800" data-payment-link-covers>
                            {{ trans_choice('For booking :booking, trip :trips.|For booking :booking, all :count trips: :trips.', $covers['count'], ['booking' => $covers['booking'], 'trips' => $covers['trips'], 'count' => $covers['count']]) }}
                        </p>
                    @endif
                    @if ($paymentLinkUrl === '')
                        {{-- Quick shares: a deposit of 25% or 50% of the booking total, or the full balance. --}}
                        @php $shares = $this->paymentShares(); @endphp
                        @if ($shares !== [])
                            <div class="mb-3 grid grid-cols-3 gap-2" data-payment-shares>
                                @foreach ($shares as $share)
                                    @php $chosen = abs((float) $paymentAmount - $share['amount']) < 0.0005; @endphp
                                    <button type="button" wire:click="usePaymentShare({{ $share['percent'] }})"
                                            class="rounded-xl px-2 py-2 text-center ring-1 transition {{ $chosen ? 'bg-primary-400 text-chrome-900 ring-primary-500' : 'bg-white text-chrome-700 ring-chrome-200 hover:bg-chrome-50' }}">
                                        <span class="block text-sm font-semibold">{{ $share['percent'] === 100 ? __('Full balance') : __('Pay :percent%', ['percent' => $share['percent']]) }}</span>
                                        <span class="block text-xs" dir="ltr">{{ \App\Erp\Views\ValueFormat::money($share['amount']) }}</span>
                                    </button>
                                @endforeach
                            </div>
                        @endif

                        <label class="{{ $lbl ?? 'text-sm font-medium text-chrome-700' }}">{{ __('Amount to charge') }} *</label>
                        <div class="mt-1 flex items-center gap-2">
                            <input type="number" step="0.001" min="0" wire:model="paymentAmount" class="o-input w-full" dir="ltr">
                            <span class="text-sm text-chrome-500">{{ __('BHD') }}</span>
                        </div>
                        <p class="mt-1 text-[11px] text-chrome-400">{{ __('Pre-filled with the balance. Pick 25% or 50% for a deposit, or type any amount.') }}</p>
                        @error('paymentAmount') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    @else
                        <p class="mb-2 text-sm text-emerald-700">{{ __('Link created. Send it to the customer:') }}</p>
                        <div x-data="{ copied: false }" class="flex items-center gap-2">
                            <input type="text" value="{{ $paymentLinkUrl }}" readonly onclick="this.select()"
                                   class="o-input w-full bg-chrome-50 text-xs text-chrome-600" dir="ltr">
                            <button type="button"
                                    x-on:click="navigator.clipboard.writeText('{{ $paymentLinkUrl }}'); copied = true; setTimeout(() => copied = false, 1500)"
                                    class="o-btn-ghost shrink-0 text-sm">
                                <span x-show="!copied">{{ __('Copy') }}</span>
                                <span x-show="copied" x-cloak>{{ __('Copied') }}</span>
                            </button>
                        </div>
                    @endif
                </div>

                <div class="flex items-center justify-end gap-2 border-t border-chrome-200 px-5 py-3">
                    @if ($paymentLinkUrl === '')
                        <button type="button" wire:click="createPaymentLink" wire:loading.attr="disabled" class="o-btn-primary text-sm">{{ __('Create link') }}</button>
                        <button type="button" wire:click="closePaymentLink" class="o-btn-ghost text-sm">{{ __('Cancel') }}</button>
                    @else
                        <button type="button" wire:click="closePaymentLink" class="o-btn-primary text-sm">{{ __('Done') }}</button>
                    @endif
                </div>
            </div>
        </div>
    @endif

    {{-- Quick preview — every leg of the job at a glance, read-only. Opened
         from the reference cell so the office can check a booking's shape
         (route, crew, money) without leaving the list. --}}
    @if ($previewing !== null)
        @php
            $sb2 = [
                'queue' => 'bg-amber-100 text-amber-700',
                'confirmed' => 'bg-sky-100 text-sky-700',
                'active' => 'bg-indigo-100 text-indigo-700',
                'completed' => 'bg-emerald-100 text-emerald-700',
                'cancelled' => 'bg-red-100 text-red-700',
            ];
        @endphp
        <div class="fixed inset-0 z-50 overflow-y-auto bg-chrome-900/40 p-4" wire:key="preview-{{ $previewing->id }}"
             x-on:keydown.escape.window="$wire.closePreview()">
            <div class="flex min-h-full items-start justify-center py-8">
                <div class="w-full max-w-2xl rounded-2xl bg-white shadow-pop" x-on:click.outside="$wire.closePreview()">
                    <div class="flex items-center justify-between border-b border-chrome-200 px-5 py-3">
                        <h2 class="text-base font-bold text-chrome-900">
                            {{ __('Booking preview') }} — {{ $previewing->reference }}
                        </h2>
                        <button type="button" wire:click="closePreview"
                                class="text-lg leading-none text-chrome-400 hover:text-chrome-700" aria-label="{{ __('Close') }}">&times;</button>
                    </div>

                    <div class="max-h-[75vh] overflow-y-auto px-5 py-4">
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <p class="text-xs font-medium uppercase tracking-wide text-chrome-400">{{ __('Customer') }}</p>
                                <p class="font-medium text-chrome-800">{{ $previewing->customer?->name ?? '—' }}</p>
                                @if ($previewing->customer?->phone)
                                    <p class="text-xs text-chrome-500" dir="ltr">{{ $previewing->customer->phone }}</p>
                                @endif
                                @if ($previewing->pax_name)
                                    <p class="mt-1 text-xs text-chrome-500">{{ __('Passenger') }}: {{ $previewing->pax_name }}
                                        @if ($previewing->pax_contact) · {{ $previewing->pax_contact }} @endif
                                    </p>
                                @endif
                            </div>
                            <div class="sm:text-end">
                                <p class="text-xs font-medium uppercase tracking-wide text-chrome-400">{{ __('Status') }}</p>
                                <span class="inline-block rounded px-2 py-0.5 text-[11px] font-semibold uppercase {{ $sb2[$previewing->status] ?? 'bg-chrome-200 text-chrome-700' }}">{{ __(ucfirst($previewing->status)) }}</span>
                                <span class="ms-1 inline-block rounded px-2 py-0.5 text-[11px] font-semibold uppercase {{ \Modules\Limousine\Models\LimoBooking::paymentBadgeClass($previewing->paymentState()) }}">{{ \Modules\Limousine\Models\LimoBooking::paymentLabel($previewing->paymentState()) }}</span>
                                @if ($previewing->company_reference)
                                    <p class="mt-1 text-xs text-chrome-500">{{ __('Company reference') }}: {{ $previewing->company_reference }}</p>
                                @endif
                            </div>
                        </div>

                        {{-- Six columns do not fit a phone. `overflow-hidden` (which
                             was here for the rounded corners) simply CUT the last of
                             them off with no way to reach them — the vehicle, the
                             status and every amount were unreachable on a phone. It
                             scrolls sideways now, like the bookings table itself, and
                             the min-width keeps the columns legible rather than
                             letting each one wrap into a stack of single words. --}}
                        <div class="mt-4 overflow-x-auto rounded-xl ring-1 ring-chrome-200">
                            <table class="w-full min-w-[38rem] text-sm">
                                <thead class="bg-chrome-50 text-[11px] font-semibold uppercase tracking-wide text-chrome-500">
                                    <tr>
                                        <th class="whitespace-nowrap px-3 py-2 text-start">{{ __('Trip') }}</th>
                                        <th class="px-3 py-2 text-start">{{ __('Route') }}</th>
                                        <th class="whitespace-nowrap px-3 py-2 text-start">{{ __('Date') }}</th>
                                        <th class="whitespace-nowrap px-3 py-2 text-start">{{ __('Vehicle') }} / {{ __('Driver') }}</th>
                                        <th class="whitespace-nowrap px-3 py-2 text-start">{{ __('Status') }}</th>
                                        <th class="whitespace-nowrap px-3 py-2 text-end">{{ __('Amount') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-chrome-100">
                                    @foreach ($previewing->legs as $pl)
                                        <tr class="text-chrome-700">
                                            <td class="px-3 py-2 font-medium">
                                                <button type="button"
                                                        x-data="{ done: false }"
                                                        x-on:click="
                                                            $store.limoTrip.copy(@js($previewWhatsapp[$pl->id] ?? ''));
                                                            done = true; setTimeout(() => done = false, 1500)
                                                        "
                                                        title="{{ __('Copy trip details for WhatsApp') }}"
                                                        class="hover:underline">
                                                    <span x-show="! done">{{ $pl->reference }}</span>
                                                    <span x-show="done" x-cloak class="text-emerald-600">✓ {{ __('Copied') }}</span>
                                                </button>
                                            </td>
                                            <td class="px-3 py-2 text-chrome-600">
                                                @if ($pl->service_type === 'chauffeur')
                                                    {{ __('Chauffeur') }} — {{ $pl->from_location ?: '—' }}
                                                @else
                                                    {{ $pl->from_location ?: '—' }} → {{ $pl->to_location ?: '—' }}
                                                @endif
                                            </td>
                                            <td class="whitespace-nowrap px-3 py-2 text-chrome-600">{{ $pl->start_at?->isoFormat('DD-MMM-YY HH:mm') ?? '—' }}</td>
                                            <td class="px-3 py-2 text-chrome-600">{{ $pl->vehicle ?: '—' }}@if($pl->driver) · {{ $pl->driver }} @endif</td>
                                            <td class="whitespace-nowrap px-3 py-2">
                                                <span class="rounded px-2 py-0.5 text-[11px] font-semibold uppercase {{ $sb2[$pl->status] ?? 'bg-chrome-200 text-chrome-700' }}">{{ __(ucfirst((string) $pl->status)) }}</span>
                                            </td>
                                            <td class="whitespace-nowrap px-3 py-2 text-end font-medium">{{ $money((float) $pl->net_amount) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot class="bg-chrome-50 text-sm">
                                    <tr class="font-semibold text-chrome-800">
                                        <td colspan="5" class="px-3 py-2">{{ __('Total') }}</td>
                                        <td class="whitespace-nowrap px-3 py-2 text-end">{{ $money($previewing->netAmount()) }}</td>
                                    </tr>
                                    <tr class="text-emerald-700">
                                        <td colspan="5" class="px-3 py-2">{{ __('Already received') }}</td>
                                        <td class="whitespace-nowrap px-3 py-2 text-end">{{ $money((float) $previewing->advance) }}</td>
                                    </tr>
                                    @php $previewDue = $previewing->balanceDue(); @endphp
                                    <tr class="font-semibold {{ $previewDue > 0 ? 'text-amber-700' : 'text-chrome-500' }}">
                                        <td colspan="5" class="px-3 py-2">{{ __('Still owed') }}</td>
                                        <td class="whitespace-nowrap px-3 py-2 text-end">{{ $money($previewDue) }}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>

                        @if ($previewing->notes)
                            <div class="mt-4">
                                <p class="text-xs font-medium uppercase tracking-wide text-chrome-400">{{ __('Notes') }}</p>
                                <p class="mt-1 whitespace-pre-line text-sm text-chrome-600">{{ $previewing->notes }}</p>
                            </div>
                        @endif
                    </div>

                    <div class="flex items-center justify-between gap-2 border-t border-chrome-200 px-5 py-3">
                        <button type="button" wire:click="closePreview" class="o-btn-ghost text-sm">{{ __('Close') }}</button>
                        <a href="{{ url('/app/limousine/booking/' . $previewing->id) }}" wire:navigate class="o-btn-primary text-sm">{{ __('Open full booking') }}</a>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
