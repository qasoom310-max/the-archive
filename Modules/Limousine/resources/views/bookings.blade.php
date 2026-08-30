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
            'cancelled' => __('Cancelled'),
        ];
    @endphp
    <div class="mb-4 flex flex-wrap items-center gap-1 border-b border-chrome-200">
        @foreach ($tabs as $key => $label)
            @php $n = $key === 'all' ? $totalCount : (int) $counts->get($key, 0); @endphp
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
                    @foreach ($headings as $key => $label)
                        <th class="px-2 py-2 {{ in_array($key, ['amount', 'received', 'balance'], true) ? 'text-end' : 'text-start' }} {{ $vis[$key] ?? '' }}">{{ $label }}</th>
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
                    @endphp
                    {{-- `group` so the pinned Actions cell can mirror the row hover
                         (it needs its own background to sit above the scroll). --}}
                    <tr wire:key="leg-{{ $leg->id }}" class="group hover:bg-chrome-50">
                        <td class="hidden px-2 py-2 text-chrome-400 sm:table-cell">{{ $legs->firstItem() + $i }}</td>
                        <td class="px-2 py-2 font-medium text-chrome-800">
                            {{ $row['reference'] ?: '—' }}
                            <span class="block text-[11px] font-normal text-chrome-400">{{ $row['booking_reference'] }}</span>
                        </td>
                        <td class="hidden px-2 py-2 text-chrome-600 md:table-cell">{{ $row['from_date'] ?: '—' }}</td>
                        <td class="hidden px-2 py-2 text-chrome-600 lg:table-cell">{{ $row['to_date'] ?: '—' }}</td>
                        <td class="hidden px-2 py-2 text-chrome-600 lg:table-cell">{{ $row['type'] }}</td>
                        <td class="px-2 py-2 text-chrome-700">{{ $row['customer'] ?: '—' }}</td>
                        {{-- Amount is this leg's; Received and Balance are the
                             booking's, because the customer settles the whole job. --}}
                        <td class="hidden px-2 py-2 text-end font-medium text-chrome-800 sm:table-cell">{{ $money($row['amount']) }}</td>
                        <td class="hidden px-2 py-2 text-end text-emerald-700 xl:table-cell">{{ $money($row['received']) }}</td>
                        <td class="hidden px-2 py-2 text-end xl:table-cell {{ $row['balance'] > 0 ? 'text-amber-700' : 'text-chrome-400' }}">{{ $money($row['balance']) }}</td>
                        <td class="hidden px-2 py-2 text-chrome-600 lg:table-cell">{{ $row['pickup'] ?: '—' }}</td>
                        <td class="hidden px-2 py-2 text-chrome-600 lg:table-cell">{{ $row['dropoff'] ?: '—' }}</td>
                        <td class="hidden px-2 py-2 xl:table-cell">
                            @if ($row['vehicle'] !== '')
                                <span class="text-chrome-700">{{ $row['vehicle'] }}</span>
                                @if ($canAssign)
                                    <button type="button" wire:click="openAssign({{ $leg->id }})"
                                            class="ms-2 text-xs font-medium text-primary-700 hover:underline">{{ __('Change') }}</button>
                                @endif
                            @elseif ($canAssign)
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
                            @elseif ($canAssign)
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
                            <span class="rounded px-2 py-0.5 text-[11px] font-semibold uppercase {{ $sb }}">{{ __(ucfirst($row['status'])) }}</span>
                            @if ($next !== null && $canAssign)
                                <button type="button" wire:click="advanceLeg({{ $leg->id }}, '{{ $next[0] }}')"
                                        class="ms-2 text-xs font-medium text-primary-700 hover:underline">{{ $next[1] }}</button>
                            @endif
                        </td>
                        <td class="hidden px-2 py-2 md:table-cell"><span class="rounded px-2 py-0.5 text-[11px] font-semibold uppercase {{ $row['payment'] === 'paid' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' }}">{{ __(ucfirst($row['payment'])) }}</span></td>
                        <td class="sticky end-0 z-10 bg-white px-2 py-2 shadow-[-8px_0_8px_-8px_rgba(0,0,0,0.12)] group-hover:bg-chrome-50">
                            <a href="{{ url('/app/limousine/booking/' . $leg->legable_id) }}" wire:navigate
                               class="text-xs font-medium text-primary-700 hover:underline">{{ __('Open') }}</a>
                            @if ($canAssign)
                                {{-- Booking-level details (passenger, flight, rate…) are
                                     shared by every leg of the job, so they are edited
                                     per booking rather than per leg. --}}
                                <button type="button" wire:click="openEdit({{ $leg->legable_id }})"
                                        class="ms-2 text-xs font-medium text-primary-700 hover:underline">{{ __('Edit') }}</button>
                            @endif
                            {{-- Service Order: the per-trip sheet. Staff open the PDF;
                                 the customer gets a link to sign it, which is the proof
                                 the driver arrived and the trip was used. --}}
                            <a href="{{ url('/app/limousine/service-order/' . $leg->id) }}" target="_blank" rel="noopener"
                               class="ms-2 text-xs font-medium text-primary-700 hover:underline">{{ __('Service order') }}</a>
                            @if ($canAssign)
                                @php $canSign = $signable[$leg->id] ?? true; @endphp
                                @if ($leg->isSigned())
                                    {{-- Signed: the proof exists, nothing more to send. --}}
                                    <span class="ms-2 text-xs font-semibold text-emerald-600"
                                          title="{{ __('Signed by') }} {{ $leg->signed_name }} · {{ $leg->signed_at?->isoFormat('DD-MMM-YY HH:mm') }}">✓ {{ __('Signed') }}</span>
                                @else
                                    @if ($leg->service_order_sent_at)
                                        {{-- Already sent — say so, and keep Resend beside it
                                             for a bounced mail or a lapsed link. --}}
                                        <span class="ms-2 text-xs font-medium text-chrome-500"
                                              title="{{ $leg->service_order_sent_at->isoFormat('DD-MMM-YY HH:mm') }}">
                                            ✓ {{ $canSign ? __('Sent to sign') : __('Company notified') }}
                                        </span>
                                        <button type="button" wire:click="sendServiceOrder({{ $leg->id }})"
                                                wire:confirm="{{ __('Send this again?') }}"
                                                class="ms-1 text-xs font-medium text-primary-700 hover:underline">{{ __('Resend') }}</button>
                                    @else
                                        {{-- A company books for its guest, so it is told the
                                             driver arrived rather than asked to sign for a
                                             trip it was not on. --}}
                                        <button type="button" wire:click="sendServiceOrder({{ $leg->id }})"
                                                wire:confirm="{{ $canSign
                                                    ? __('Email the customer a link to sign this service order?')
                                                    : __('Email the company that the driver has reached their customer?') }}"
                                                class="ms-2 text-xs font-medium text-primary-700 hover:underline">
                                            {{ $canSign ? __('Send to sign') : __('Notify company') }}
                                        </button>
                                    @endif
                                @endif
                            @endif
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

                <div class="mt-4 space-y-4">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Car') }}</label>
                        <select wire:model="assignCar" class="o-input w-full">
                            <option value="">{{ count($carOptions) ? __('— Select —') : __('No cars available') }}</option>
                            @foreach ($carOptions as $opt)<option value="{{ $opt['value'] }}">{{ $opt['label'] }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Driver') }}</label>
                        <select wire:model="assignDriver" class="o-input w-full">
                            <option value="">{{ count($driverOptions) ? __('— Select —') : __('No drivers available') }}</option>
                            @foreach ($driverOptions as $opt)<option value="{{ $opt['value'] }}">{{ $opt['label'] }}</option>@endforeach
                        </select>
                        {{-- Same people drive for both apps, so the list is shared. --}}
                        <p class="mt-1 text-xs text-chrome-500">
                            <a href="{{ url('/app/limousine/driver') }}" class="text-primary-700 hover:underline">{{ __('Manage drivers') }}</a>
                        </p>
                    </div>
                </div>

                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" wire:click="closeAssign" class="o-btn-ghost text-sm">{{ __('Close') }}</button>
                    <button type="button" wire:click="saveAssign" wire:loading.attr="disabled" class="o-btn-primary text-sm">{{ __('Save') }}</button>
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
                            {{ __('Update booking details') }} — {{ $editing->reference }}
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
                                <p class="mt-1 text-[11px] text-chrome-400">{{ __('From the trip legs — edit on the full booking.') }}</p>
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

                            <div>
                                <label class="{{ $lbl }}">{{ __('Car details') }}</label>
                                <input type="text" wire:model="edit.car_details" class="o-input mt-1 w-full">
                            </div>
                            <div>
                                <label class="{{ $lbl }}">{{ __('Driver') }}</label>
                                <input type="text" wire:model="edit.driver_name" class="o-input mt-1 w-full">
                                {{-- The car + driver actually dispatched are set per leg
                                     from the queue; this is the name written on the job. --}}
                                <p class="mt-1 text-[11px] text-chrome-400">{{ __('Assign the dispatched driver per leg from the queue.') }}</p>
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
                            <div>
                                <label class="{{ $lbl }}">{{ __('Company reference') }}</label>
                                <input type="text" wire:model="edit.company_reference" class="o-input mt-1 w-full">
                            </div>

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
                           class="text-xs text-chrome-500 hover:underline">{{ __('Open full booking (trip legs, pricing)') }}</a>
                        <div class="flex items-center gap-2">
                            <button type="button" wire:click="saveEdit" wire:loading.attr="disabled" class="o-btn-primary text-sm">{{ __('Update booking details') }}</button>
                            <button type="button" wire:click="cancelEdit" class="o-btn-ghost text-sm">{{ __('Cancel') }}</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
