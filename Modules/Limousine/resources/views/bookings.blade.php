<div class="mx-auto w-full p-4 sm:p-6">
    <x-page-header :title="__('Bookings')" :subtitle="__('Trip bookings.')" icon="calendar" accent="indigo">
        <x-slot:actions>
            <a href="{{ url('/app/limousine/booking/new') }}" wire:navigate class="o-btn-primary">
                <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10 5a1 1 0 0 1 1 1v3h3a1 1 0 1 1 0 2h-3v3a1 1 0 1 1-2 0v-3H6a1 1 0 1 1 0-2h3V6a1 1 0 0 1 1-1Z"/></svg>
                {{ __('New booking') }}
            </a>
        </x-slot:actions>
    </x-page-header>

    @if (session('booking_status'))
        <div class="mb-4 rounded-lg bg-primary-50 px-4 py-2.5 text-sm font-medium text-chrome-800 ring-1 ring-primary-200">
            {{ session('booking_status') }}
        </div>
    @endif

    @php
        $tabs = [
            'all' => __('All'),
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
    <div class="mb-4 flex flex-wrap items-center gap-1 border-b border-chrome-200">
        @foreach ($tabs as $key => $label)
            @php $n = match ($key) {
                'all' => $totalCount,
                'unpaid' => $unpaidCount,
                default => (int) $counts->get($key, 0),
            }; @endphp
            <button wire:click="$set('tab', '{{ $key }}')"
                class="-mb-px flex items-center gap-1.5 border-b-2 px-4 py-2 text-sm font-medium {{ $tab === $key ? 'border-primary-600 text-primary-700' : 'border-transparent text-chrome-500 hover:text-chrome-800' }}">
                {{ $label }}
                <span class="rounded-full bg-chrome-100 px-1.5 text-[11px] text-chrome-500">{{ $n }}</span>
            </button>
        @endforeach
    </div>

    <div class="mb-4 flex flex-wrap items-end gap-3">
        <div class="min-w-[16rem] flex-1">
            <label class="mb-1 block text-xs font-medium text-chrome-500">{{ __('Search') }}</label>
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
            <label class="mb-1 block text-xs font-medium text-chrome-500">{{ __('Pick-up from') }}</label>
            <input type="date" wire:model.live="from" class="o-input text-sm">
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium text-chrome-500">{{ __('Pick-up to') }}</label>
            <input type="date" wire:model.live="to" class="o-input text-sm">
        </div>
        @if ($from !== '' || $to !== '' || $search !== '')
            {{-- Clears the search too, so one button resets the whole filter. --}}
            <button wire:click="$set('from', ''); $set('to', ''); $set('search', '')" class="pb-2 text-sm text-chrome-500 hover:underline">{{ __('Clear') }}</button>
        @endif
    </div>

    {{-- Export bar. CSV / Excel / PDF / Print are server-rendered from the same
         rows as the table (filters ride along in the query string); Copy lifts
         the rendered table client-side, so it needs no endpoint. --}}
    <div class="mb-3 flex flex-wrap items-center gap-2" x-data="limoQueueCopy">
        <button type="button" x-on:click="copyTable($el)"
                class="rounded-lg border border-chrome-200 px-3 py-1.5 text-xs font-medium text-chrome-600 transition hover:bg-chrome-50">
            <span x-show="! copied">{{ __('Copy') }}</span>
            <span x-show="copied" x-cloak class="text-emerald-600">{{ __('Copied') }}</span>
        </button>
        <a href="{{ url('/app/limousine/booking/export/csv') }}?{{ $exportQuery }}"
           class="rounded-lg border border-chrome-200 px-3 py-1.5 text-xs font-medium text-chrome-600 transition hover:bg-chrome-50">{{ __('CSV') }}</a>
        <a href="{{ url('/app/limousine/booking/export/excel') }}?{{ $exportQuery }}"
           class="rounded-lg border border-chrome-200 px-3 py-1.5 text-xs font-medium text-chrome-600 transition hover:bg-chrome-50">{{ __('Excel') }}</a>
        <a href="{{ url('/app/limousine/booking/export/pdf') }}?{{ $exportQuery }}"
           class="rounded-lg border border-chrome-200 px-3 py-1.5 text-xs font-medium text-chrome-600 transition hover:bg-chrome-50">{{ __('PDF') }}</a>
        <a href="{{ url('/app/limousine/booking/export/print') }}?{{ $exportQuery }}" target="_blank" rel="noopener"
           class="rounded-lg border border-chrome-200 px-3 py-1.5 text-xs font-medium text-chrome-600 transition hover:bg-chrome-50">{{ __('Print') }}</a>
    </div>

    {{-- 17 data columns can never fit a phone, so rather than force a sideways
         scroll the table sheds columns as the screen narrows: what identifies
         and actions a job always stays, the rest return as there is room.
           phone  reference · customer · status · actions
           sm     + no. · amount
           md     + from date · payment
           lg     + to date · type · pickup · drop off
           xl     + received · balance · vehicle · driver
           2xl    everything (added by, comments, booked time)
         Exports and Print carry ALL columns whatever the screen, so nothing is
         lost — it is only hidden from this view. --}}
    @php
        $vis = [
            'reference' => '',
            'from_date' => 'hidden md:table-cell',
            'to_date' => 'hidden lg:table-cell',
            'type' => 'hidden lg:table-cell',
            'customer' => '',
            'amount' => 'hidden sm:table-cell',
            'received' => 'hidden xl:table-cell',
            'balance' => 'hidden xl:table-cell',
            'pickup' => 'hidden lg:table-cell',
            'dropoff' => 'hidden lg:table-cell',
            'vehicle' => 'hidden xl:table-cell',
            'driver' => 'hidden xl:table-cell',
            'added_by' => 'hidden 2xl:table-cell',
            'comments' => 'hidden 2xl:table-cell',
            'booked_time' => 'hidden 2xl:table-cell',
            'status' => '',
            'payment' => 'hidden md:table-cell',
        ];
    @endphp
    <div class="overflow-x-auto rounded-2xl bg-white shadow-sm ring-1 ring-chrome-900/[0.06]">
        <table id="limo-queue" class="w-full table-auto divide-y divide-chrome-100 text-xs">
            <thead class="bg-chrome-50 text-xs font-semibold uppercase tracking-wide text-chrome-500">
                <tr>
                    <th class="hidden px-2 py-2 text-start sm:table-cell">{{ __('Sl No.') }}</th>
                    {{-- Every column sorts, both ways. The arrow is always drawn so
                         a column reads as sortable before anyone clicks it, and
                         only darkens on the one actually doing the sorting. --}}
                    @foreach ($headings as $key => $label)
                        @php
                            $isMoney = in_array($key, ['amount', 'received', 'balance'], true);
                            $sorted = $sort === $key;
                        @endphp
                        <th class="px-2 py-2 {{ $isMoney ? 'text-end' : 'text-start' }} {{ $vis[$key] ?? '' }}"
                            @if ($sorted) aria-sort="{{ $dir === 'asc' ? 'ascending' : 'descending' }}" @endif>
                            <button type="button" wire:click="sortBy('{{ $key }}')"
                                class="inline-flex items-center gap-1 transition hover:text-chrome-800 {{ $isMoney ? 'flex-row-reverse' : '' }} {{ $sorted ? 'text-chrome-800' : '' }}"
                                title="{{ __('Sort by :column', ['column' => $label]) }}">
                                <span>{{ $label }}</span>
                                <svg class="size-3 shrink-0 transition {{ $sorted ? 'text-primary-600' : 'text-chrome-300' }} {{ $sorted && $dir === 'asc' ? 'rotate-180' : '' }}"
                                     viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path fill-rule="evenodd" d="M10 15a1 1 0 0 1-.71-.29l-5-5a1 1 0 1 1 1.42-1.42L10 12.59l4.29-4.3a1 1 0 1 1 1.42 1.42l-5 5A1 1 0 0 1 10 15Z" clip-rule="evenodd"/>
                                </svg>
                            </button>
                        </th>
                    @endforeach
                    {{-- Columns are sized to fit the window, but a narrow laptop can still
                         overflow — keep Open/Edit pinned to the trailing edge so they can
                         never end up off-screen. --}}
                    <th class="sticky end-0 z-20 bg-chrome-50 px-2 py-2 text-start shadow-[-8px_0_8px_-8px_rgba(0,0,0,0.12)]">{{ __('Actions') }}</th>
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
                        $money = fn (float $v): string => \App\Erp\Views\ValueFormat::money($v);
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
                        <td class="hidden px-2 py-2 text-chrome-400 sm:table-cell">{{ $legs->firstItem() + $i }}</td>
                        {{-- The reference IS the copy button: press it and the whole
                             trip lands on the clipboard, formatted for WhatsApp. That
                             is what the office does with a booking, so it should be
                             one press from the number they are already looking at. --}}
                        <td class="px-2 py-2 font-medium text-chrome-800">
                            <button type="button"
                                    x-data="{ done: false }"
                                    x-on:click="
                                        $store.limoTrip.copy(@js($whatsapp[$leg->id] ?? ''));
                                        done = true; setTimeout(() => done = false, 1500)
                                    "
                                    title="{{ __('Copy trip details for WhatsApp') }}"
                                    class="text-start font-medium text-primary-700 hover:underline">
                                <span x-show="! done">{{ $row['reference'] ?: '—' }}</span>
                                <span x-show="done" x-cloak class="text-emerald-600">✓ {{ __('Copied') }}</span>
                            </button>
                            <span class="block text-[11px] font-normal text-chrome-400">{{ $row['booking_reference'] }}</span>
                        </td>
                        <td class="hidden px-2 py-2 text-chrome-600 md:table-cell">{{ $row['from_date'] ?: '—' }}</td>
                        <td class="hidden px-2 py-2 text-chrome-600 lg:table-cell">{{ $row['to_date'] ?: '—' }}</td>
                        <td class="hidden px-2 py-2 text-chrome-600 lg:table-cell">{{ $row['type'] }}</td>
                        <td class="px-2 py-2 text-chrome-700">{{ $row['customer'] ?: '—' }}</td>
                        {{-- Amount is this leg's; Received and Balance are the
                             booking's, because the customer settles the whole job. --}}
                        {{-- Amount is THIS trip's price. Received and Balance are the
                             whole booking's: the customer settles the job, not a leg
                             of it, so a booking of three trips shows one balance
                             repeated down its rows rather than a third on each. The
                             tooltips say so, because two money columns that repeat
                             and one that doesn't otherwise reads as double-counting. --}}
                        <td class="hidden px-2 py-2 text-end font-medium text-chrome-800 sm:table-cell"
                            title="{{ __('Price of this trip') }}">{{ $money($row['amount']) }}</td>
                        <td class="hidden px-2 py-2 text-end text-emerald-700 xl:table-cell"
                            title="{{ __('Received against booking :reference — the whole job, not this trip alone.', ['reference' => $row['booking_reference']]) }}">{{ $money($row['received']) }}</td>
                        <td class="hidden px-2 py-2 text-end xl:table-cell {{ $row['balance'] > 0 ? 'text-amber-700' : 'text-chrome-400' }}"
                            title="{{ __('Still owed on booking :reference — the whole job, not this trip alone.', ['reference' => $row['booking_reference']]) }}">{{ $money($row['balance']) }}</td>
                        <td class="hidden px-2 py-2 text-chrome-600 lg:table-cell">{{ $row['pickup'] ?: '—' }}</td>
                        <td class="hidden px-2 py-2 text-chrome-600 lg:table-cell">{{ $row['dropoff'] ?: '—' }}</td>
                        <td class="hidden px-2 py-2 xl:table-cell">
                            @if ($row['vehicle'] !== '')
                                <span class="text-chrome-700">{{ $row['vehicle'] }}</span>
                                @if ($mayEdit)
                                    <button type="button" wire:click="openAssign({{ $leg->id }})"
                                            class="ms-2 text-xs font-medium text-primary-700 hover:underline">{{ __('Change') }}</button>
                                @endif
                            @elseif ($mayEdit)
                                <button type="button" wire:click="openAssign({{ $leg->id }})"
                                        class="rounded-lg border border-chrome-200 px-2.5 py-1 text-xs font-medium text-chrome-600 transition hover:bg-chrome-50">
                                    {{ __('Assign car') }}
                                </button>
                            @else
                                <span class="text-chrome-400">—</span>
                            @endif
                        </td>
                        {{-- Driver is set in the same modal as the car; both are
                             per leg, since each leg is dispatched on its own. --}}
                        <td class="hidden px-2 py-2 xl:table-cell">
                            @if ($row['driver'] !== '')
                                <span class="text-chrome-700">{{ $row['driver'] }}</span>
                            @elseif ($mayEdit)
                                <button type="button" wire:click="openAssign({{ $leg->id }})"
                                        class="rounded-lg border border-chrome-200 px-2.5 py-1 text-xs font-medium text-chrome-600 transition hover:bg-chrome-50">
                                    {{ __('Assign driver') }}
                                </button>
                            @else
                                <span class="text-chrome-400">—</span>
                            @endif
                        </td>
                        <td class="hidden px-2 py-2 text-chrome-600 2xl:table-cell">{{ $row['added_by'] ?: '—' }}</td>
                        <td class="hidden max-w-[16rem] px-2 py-2 text-chrome-600 2xl:table-cell">{{ $row['comments'] ?: '—' }}</td>
                        <td class="hidden px-2 py-2 text-chrome-500 2xl:table-cell">{{ $row['booked_time'] ?: '—' }}</td>
                        <td class="px-2 py-2">
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
                            </div>
                            @if ($row['status'] === 'cancelled' && $leg->refund_outcome)
                                {{-- What the customer got back, so a cancelled row
                                     isn't a dead end for the person reading it. --}}
                                <span class="ms-2 text-[11px] text-chrome-500">
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
                        <td class="hidden px-2 py-2 md:table-cell">
                            <span class="rounded px-2 py-0.5 text-[11px] font-semibold uppercase {{ $row['payment'] === 'paid' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' }}">{{ __(ucfirst($row['payment'])) }}</span>
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
                        {{-- Icon actions. Four text links per row made the column wide
                             and hard to scan; icons keep it compact. Every one carries a
                             `title` (hover tooltip) AND an `aria-label`, so the meaning
                             is available by pointing at it and to a screen reader —
                             an icon alone would just be a mystery glyph. --}}
                        <td class="sticky end-0 z-10 bg-white px-2 py-2 shadow-[-8px_0_8px_-8px_rgba(0,0,0,0.12)] group-hover:bg-chrome-50">
                            @php $act = 'inline-flex size-7 items-center justify-center rounded-lg transition'; @endphp
                            <div class="flex items-center gap-0.5">
                                {{-- Open the full booking --}}
                                <a href="{{ url('/app/limousine/booking/' . $leg->legable_id) }}" wire:navigate
                                   title="{{ __('Open full booking') }}" aria-label="{{ __('Open full booking') }}"
                                   class="{{ $act }} text-chrome-500 hover:bg-primary-50 hover:text-primary-700">
                                    <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/>
                                    </svg>
                                </a>

                                @if ($mayEdit)
                                    {{-- Booking-level details (passenger, flight, rate…) are
                                         shared by every leg, so they are edited per booking.
                                         Gone once this trip is done: a finished trip is a
                                         record, not a draft. --}}
                                    <button type="button" wire:click="openEdit({{ $leg->legable_id }}, {{ $leg->id }})"
                                            title="{{ __('Edit booking details') }}" aria-label="{{ __('Edit booking details') }}"
                                            class="{{ $act }} text-chrome-500 hover:bg-primary-50 hover:text-primary-700">
                                        <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z"/>
                                        </svg>
                                    </button>
                                @endif

                                {{-- Service Order PDF — the per-trip sheet. --}}
                                <a href="{{ url('/app/limousine/service-order/' . $leg->id) }}" target="_blank" rel="noopener"
                                   title="{{ __('Service order (PDF)') }}" aria-label="{{ __('Service order (PDF)') }}"
                                   class="{{ $act }} text-chrome-500 hover:bg-primary-50 hover:text-primary-700">
                                    <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5A3.375 3.375 0 0 0 10.125 2.25H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/>
                                    </svg>
                                </a>

                                @if ($canAssign)
                                    @php $canSign = $signable[$leg->id] ?? true; @endphp
                                    @if ($leg->isSigned())
                                        {{-- Signed: the proof exists, nothing left to send. --}}
                                        <span class="{{ $act }} text-emerald-600"
                                              title="{{ __('Signed by') }} {{ $leg->signed_name }} · {{ $leg->signed_at?->isoFormat('DD-MMM-YY HH:mm') }}"
                                              aria-label="{{ __('Signed') }}">
                                            <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
                                            </svg>
                                        </span>
                                    @elseif ($leg->service_order_sent_at)
                                        {{-- Sent: a green tick says so, and the paper plane
                                             beside it resends a bounced mail or lapsed link. --}}
                                        <span class="{{ $act }} text-emerald-600"
                                              title="{{ ($canSign ? __('Sent to sign') : __('Company notified')) }} · {{ $leg->service_order_sent_at->isoFormat('DD-MMM-YY HH:mm') }}"
                                              aria-label="{{ $canSign ? __('Sent to sign') : __('Company notified') }}">
                                            <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/>
                                            </svg>
                                        </span>
                                        <button type="button" wire:click="sendServiceOrder({{ $leg->id }})"
                                                wire:confirm="{{ __('Send this again?') }}"
                                                title="{{ __('Resend') }}" aria-label="{{ __('Resend') }}"
                                                class="{{ $act }} text-chrome-400 hover:bg-primary-50 hover:text-primary-700">
                                            <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992V4.356m-.001 0-3.181 3.183a8.25 8.25 0 0 0-13.803 3.7M4.031 9.865v4.992m0 0h4.992m-4.993 0 3.181-3.183a8.25 8.25 0 0 0 13.803-3.7"/>
                                            </svg>
                                        </button>
                                    @else
                                        {{-- A company books for its guest, so it is told the
                                             driver arrived rather than asked to sign for a
                                             trip it was not on. --}}
                                        <button type="button" wire:click="sendServiceOrder({{ $leg->id }})"
                                                wire:confirm="{{ $canSign
                                                    ? __('Email the customer a link to sign this service order?')
                                                    : __('Email the company that the driver has reached their customer?') }}"
                                                title="{{ $canSign ? __('Send to sign') : __('Notify company') }}"
                                                aria-label="{{ $canSign ? __('Send to sign') : __('Notify company') }}"
                                                class="{{ $act }} text-chrome-500 hover:bg-primary-50 hover:text-primary-700">
                                            <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5"/>
                                            </svg>
                                        </button>
                                    @endif
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="19" class="px-4 py-10 text-center text-sm text-chrome-400">{{ __('No bookings found.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $legs->links() }}</div>

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
                    {{ $assigningLeg->from_location ?? '—' }}{{ $assigningLeg->to_location ? ' → ' . $assigningLeg->to_location : '' }}
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
                    <div>
                        <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Car') }}</label>
                        <x-searchable-select wire:model="assignCar" class="o-input w-full"
                            :options="$carOptions"
                            :empty="__('No cars available')"
                            :search-placeholder="__('Search plate or model…')" />
                        @error('assignCar') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Driver') }}</label>
                        <x-searchable-select wire:model="assignDriver" class="o-input w-full"
                            :options="$driverOptions"
                            :empty="__('No drivers available')"
                            :search-placeholder="__('Search driver…')" />
                        @error('assignDriver') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        {{-- Same people drive for both apps, so the list is shared. --}}
                        <p class="mt-1 text-xs text-chrome-500">
                            <a href="{{ url('/app/limousine/driver') }}" class="text-primary-700 hover:underline">{{ __('Manage drivers') }}</a>
                        </p>
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
                            {{ $cancellingLeg->from_location }}
                            @if ($cancellingLeg->to_location) → {{ $cancellingLeg->to_location }} @endif
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
                                <label class="mt-2 flex items-center gap-2 text-xs font-medium">
                                    <input type="checkbox" wire:model="cancelAsCoupon"
                                           class="size-4 rounded border-chrome-300 text-primary-600 focus:ring-primary-500">
                                    {{ __('Give it as a coupon instead of refunding the money') }}
                                </label>
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
                                            <input type="datetime-local" wire:model="editLeg.start_at" class="o-input mt-1 w-full">
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
                                            <input type="datetime-local" wire:model="editLeg.start_at" class="o-input mt-1 w-full">
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
                                <input type="datetime-local" wire:model="edit.pickup_at" class="o-input mt-1 w-full">
                                @error('edit.pickup_at') <p class="{{ $err }}">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="{{ $lbl }}">{{ __('Booking to') }}</label>
                                <input type="datetime-local" wire:model="edit.booking_to" class="o-input mt-1 w-full">
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
</div>
