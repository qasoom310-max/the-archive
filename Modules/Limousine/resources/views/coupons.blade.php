@php $money = fn ($v) => \App\Erp\Views\ValueFormat::money($v); @endphp

<div class="mx-auto w-full max-w-6xl p-4 sm:p-6">
    <x-page-header :title="__('Refund coupons')" :subtitle="__('Credit from cancelled trips, spendable on future bookings.')" icon="ticket" accent="indigo">
        <x-slot:actions>
            <a href="{{ url('/app/limousine/booking') }}" wire:navigate class="o-btn-ghost">{{ __('Bookings') }}</a>
        </x-slot:actions>
    </x-page-header>

    @if (session('coupon_status'))
        <div class="mb-4 rounded-lg bg-primary-50 px-4 py-2.5 text-sm font-medium text-chrome-800 ring-1 ring-primary-200">
            {{ session('coupon_status') }}
        </div>
    @endif

    {{-- The one figure worth leading with: credit the business still owes. --}}
    <div class="mb-5 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/5 sm:p-5">
        <p class="text-[11px] font-semibold uppercase tracking-wide text-chrome-500">{{ __('Outstanding credit') }}</p>
        <p class="mt-1 text-2xl font-bold text-amber-600">{{ $money($outstanding) }}</p>
        <p class="mt-0.5 text-xs text-chrome-400">{{ __('Unspent, unexpired coupons — value customers can still put towards a trip.') }}</p>
    </div>

    @php
        $tabs = ['active' => __('Active'), 'used' => __('Used'), 'expired' => __('Expired'), 'all' => __('All')];
    @endphp
    <div class="mb-4 flex flex-wrap items-center gap-1 border-b border-chrome-200">
        @foreach ($tabs as $key => $label)
            <button wire:click="$set('tab', '{{ $key }}')"
                class="-mb-px flex items-center gap-1.5 border-b-2 px-4 py-2 text-sm font-medium {{ $tab === $key ? 'border-primary-600 text-primary-700' : 'border-transparent text-chrome-500 hover:text-chrome-800' }}">
                {{ $label }}
                <span class="rounded-full bg-chrome-100 px-1.5 text-[11px] text-chrome-500">{{ $counts[$key] ?? 0 }}</span>
            </button>
        @endforeach
    </div>

    <div class="mb-4">
        <input type="search" wire:model.live.debounce.300ms="search" class="o-input w-full max-w-md text-sm"
               placeholder="{{ __('Coupon code, customer, or cancelled trip reference…') }}">
    </div>

    <div class="overflow-x-auto rounded-2xl bg-white shadow-sm ring-1 ring-chrome-900/[0.06]">
        <table class="w-full divide-y divide-chrome-100 text-sm">
            <thead class="bg-chrome-50 text-xs font-semibold uppercase tracking-wide text-chrome-500">
                <tr>
                    <th class="px-3 py-2 text-start">{{ __('Code') }}</th>
                    <th class="px-3 py-2 text-start">{{ __('Customer') }}</th>
                    <th class="hidden px-3 py-2 text-start sm:table-cell">{{ __('From trip') }}</th>
                    <th class="px-3 py-2 text-end">{{ __('Issued') }}</th>
                    <th class="hidden px-3 py-2 text-end md:table-cell">{{ __('Used') }}</th>
                    <th class="px-3 py-2 text-end">{{ __('Remaining') }}</th>
                    <th class="hidden px-3 py-2 text-start lg:table-cell">{{ __('Expires') }}</th>
                    <th class="px-3 py-2 text-start">{{ __('Status') }}</th>
                    <th class="px-3 py-2 text-start">{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-chrome-50">
                @forelse ($rows as $c)
                    @php
                        $badge = [
                            'active' => 'bg-emerald-100 text-emerald-700',
                            'used' => 'bg-chrome-200 text-chrome-700',
                            'expired' => 'bg-red-100 text-red-700',
                        ][$c->state()] ?? 'bg-chrome-100 text-chrome-600';
                    @endphp
                    <tr wire:key="cpn-{{ $c->id }}" class="hover:bg-chrome-50">
                        <td class="px-3 py-2 font-semibold text-chrome-900">{{ $c->code }}</td>
                        <td class="px-3 py-2 text-chrome-700">{{ $c->customer?->name ?? '—' }}</td>
                        <td class="hidden px-3 py-2 text-chrome-500 sm:table-cell">{{ $c->leg_reference ?: '—' }}</td>
                        <td class="px-3 py-2 text-end tabular-nums text-chrome-700">{{ $money($c->amount) }}</td>
                        <td class="hidden px-3 py-2 text-end tabular-nums text-chrome-500 md:table-cell">{{ $money($c->used()) }}</td>
                        <td class="px-3 py-2 text-end tabular-nums font-bold {{ $c->remaining() > 0 && ! $c->isExpired() ? 'text-emerald-700' : 'text-chrome-400' }}">
                            {{ $money($c->remaining()) }}
                        </td>
                        <td class="hidden px-3 py-2 text-chrome-500 lg:table-cell">{{ $c->expires_at?->isoFormat('DD-MMM-YY') ?? '—' }}</td>
                        <td class="px-3 py-2">
                            <span class="rounded px-2 py-0.5 text-[11px] font-semibold uppercase {{ $badge }}">{{ __(ucfirst($c->state())) }}</span>
                            @if ($c->sent_at)
                                <span class="ms-1 text-[11px] text-chrome-400"
                                      title="{{ __('Sent to :email on :date', ['email' => $c->sent_to, 'date' => $c->sent_at->isoFormat('DD-MMM-YY HH:mm')]) }}">✓ {{ __('sent') }}</span>
                            @endif
                        </td>
                        <td class="px-3 py-2">
                            @php $act = 'inline-flex size-7 items-center justify-center rounded-lg transition'; @endphp
                            <div class="flex items-center gap-0.5">
                                {{-- Spend it. Only while there is something to spend: a used
                                     or expired coupon has nothing to put towards a trip. --}}
                                @if ($canWrite && $c->isUsable())
                                    <button type="button" wire:click="openUse({{ $c->id }})"
                                            title="{{ __('Use this coupon for a booking') }}" aria-label="{{ __('Use this coupon for a booking') }}"
                                            class="{{ $act }} text-emerald-600 hover:bg-emerald-50">
                                        <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
                                        </svg>
                                    </button>
                                @endif

                                @if ($canWrite)
                                    <button type="button" wire:click="openSend({{ $c->id }})"
                                            title="{{ $c->sent_at ? __('Send the coupon again') : __('Email the coupon to the customer') }}"
                                            aria-label="{{ $c->sent_at ? __('Send the coupon again') : __('Email the coupon to the customer') }}"
                                            class="{{ $act }} text-chrome-500 hover:bg-primary-50 hover:text-primary-700">
                                        <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75"/>
                                        </svg>
                                    </button>
                                @endif

                                <a href="{{ url('/app/limousine/coupon/' . $c->id . '/pdf') }}"
                                   title="{{ __('Download the coupon (PDF)') }}" aria-label="{{ __('Download the coupon (PDF)') }}"
                                   class="{{ $act }} text-chrome-500 hover:bg-primary-50 hover:text-primary-700">
                                    <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3"/>
                                    </svg>
                                </a>
                            </div>
                        </td>
                    </tr>
                    {{-- Where the credit went, so a part-spent coupon can be
                         explained to the customer who asks. --}}
                    @if ($c->redemptions->isNotEmpty())
                        <tr wire:key="cpn-r-{{ $c->id }}" class="bg-chrome-50/60">
                            <td colspan="9" class="px-3 pb-2 pt-0 text-[11px] text-chrome-500">
                                @foreach ($c->redemptions as $r)
                                    <span class="me-3 inline-block">
                                        −{{ $money($r->amount) }}
                                        @if ($r->booking_reference) · {{ $r->booking_reference }} @endif
                                        · {{ $r->created_at?->isoFormat('DD-MMM-YY') }}
                                    </span>
                                @endforeach
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr><td colspan="9" class="px-4 py-10 text-center text-sm text-chrome-400">{{ __('No coupons here.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $coupons->links('vendor.pagination.compact') }}</div>

    {{-- ── Spend this credit ──
         The customer's money, so they choose what it buys. Both businesses take
         it, and the code travels to the form rather than being copied by hand. --}}
    @if ($using)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-chrome-900/50 p-4"
             wire:key="use-{{ $using->id }}"
             x-on:keydown.escape.window="$wire.closeUse()">
            <div class="w-full max-w-md rounded-2xl bg-white p-5 shadow-pop sm:p-6" x-on:click.outside="$wire.closeUse()">
                <h2 class="text-base font-bold text-chrome-900">{{ __('Use this coupon') }}</h2>
                <p class="mt-1 text-sm text-chrome-500">
                    {{ $using->code }} ·
                    <span class="font-semibold text-emerald-700">{{ $money($using->remaining()) }}</span>
                    {{ __('to put towards a booking') }}
                </p>

                <p class="mt-4 text-xs font-medium text-chrome-600">{{ __('What is it for?') }}</p>
                <div class="mt-2 grid gap-2 sm:grid-cols-2">
                    <button type="button" wire:click="useFor('limousine')"
                            class="rounded-xl border border-chrome-200 p-4 text-start transition hover:border-primary-400 hover:bg-primary-50">
                        <span class="block text-sm font-semibold text-chrome-800">{{ __('Limousine trip') }}</span>
                        <span class="mt-0.5 block text-xs text-chrome-500">{{ __('A driver and a car, point to point or by the hour.') }}</span>
                    </button>
                    <button type="button" wire:click="useFor('rental')"
                            class="rounded-xl border border-chrome-200 p-4 text-start transition hover:border-primary-400 hover:bg-primary-50">
                        <span class="block text-sm font-semibold text-chrome-800">{{ __('Rental car') }}</span>
                        <span class="mt-0.5 block text-xs text-chrome-500">{{ __('The customer drives, by the day, week or month.') }}</span>
                    </button>
                </div>

                <p class="mt-3 text-[11px] text-chrome-400">
                    {{ __('The new booking opens with this coupon on it — the value comes off when the booking is saved.') }}
                </p>

                <div class="mt-4 flex justify-end">
                    <button type="button" wire:click="closeUse" class="o-btn-ghost text-sm">{{ __('Cancel') }}</button>
                </div>
            </div>
        </div>
    @endif

    {{-- ── Send the coupon ──
         The address is offered filled in and stays editable: the one on file is
         often whoever placed the booking rather than whoever paid for it. --}}
    @if ($sending)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-chrome-900/50 p-4"
             wire:key="send-{{ $sending->id }}"
             x-on:keydown.escape.window="$wire.closeSend()">
            <div class="w-full max-w-md rounded-2xl bg-white p-5 shadow-pop sm:p-6" x-on:click.outside="$wire.closeSend()">
                <h2 class="text-base font-bold text-chrome-900">{{ __('Send the coupon') }}</h2>
                <p class="mt-1 text-sm text-chrome-500">
                    {{ $sending->code }} · {{ $sending->customer?->name ?? __('No customer') }} ·
                    <span class="font-semibold text-emerald-700">{{ $money($sending->remaining()) }}</span>
                </p>

                @if ($sending->sent_at)
                    <p class="mt-3 rounded-lg bg-chrome-100 px-3 py-2 text-xs text-chrome-600">
                        {{ __('Already sent to :email on :date.', [
                            'email' => $sending->sent_to,
                            'date' => $sending->sent_at->isoFormat('DD-MMM-YY HH:mm'),
                        ]) }}
                    </p>
                @endif

                <div class="mt-4">
                    <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Email') }} *</label>
                    <input type="email" wire:model="sendEmail" class="o-input w-full"
                           placeholder="{{ __('name@example.com') }}">
                    @error('sendEmail') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    <p class="mt-1 text-xs text-chrome-400">
                        {{ __('Filled in from the customer — correct it here if the coupon should go elsewhere.') }}
                    </p>
                </div>

                <p class="mt-3 text-[11px] text-chrome-400">
                    {{ __('It goes out as a PDF they can keep, with the code and the balance in the message itself.') }}
                </p>

                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" wire:click="closeSend" class="o-btn-ghost text-sm">{{ __('Cancel') }}</button>
                    <button type="button" wire:click="sendCoupon" wire:loading.attr="disabled" class="o-btn-primary text-sm">
                        {{ $sending->sent_at ? __('Send again') : __('Send') }}
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
