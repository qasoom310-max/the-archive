<div class="mx-auto max-w-7xl p-4 sm:p-6">
    <x-page-header :title="__('Quotations')" :subtitle="__('Trip estimates for customers.')" icon="quote" accent="indigo">
        <x-slot:actions>
            <a href="{{ url('/app/limousine/quotation/new') }}" wire:navigate class="o-btn-primary">
                <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10 5a1 1 0 0 1 1 1v3h3a1 1 0 1 1 0 2h-3v3a1 1 0 1 1-2 0v-3H6a1 1 0 1 1 0-2h3V6a1 1 0 0 1 1-1Z"/></svg>
                {{ __('New quotation') }}
            </a>
        </x-slot:actions>
    </x-page-header>

    @if (session('quotation_status'))
        <div class="mb-4 rounded-lg bg-primary-50 px-4 py-2.5 text-sm font-medium text-chrome-800 ring-1 ring-primary-200">
            {{ session('quotation_status') }}
        </div>
    @endif

    @php $tabs = ['all' => __('All'), 'draft' => __('Draft'), 'sent' => __('Sent'), 'accepted' => __('Accepted'), 'declined' => __('Declined'), 'converted' => __('Converted')]; @endphp
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

    <div class="overflow-x-auto rounded-2xl bg-white shadow-sm ring-1 ring-chrome-900/[0.06]">
        <table class="w-full min-w-[720px] divide-y divide-chrome-100 text-sm">
            <thead class="bg-chrome-50 text-xs font-semibold uppercase tracking-wide text-chrome-500">
                <tr>
                    <th class="px-4 py-2 text-start">{{ __('Reference') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Customer') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Valid until') }}</th>
                    <th class="px-4 py-2 text-end">{{ __('Fare') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Status') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-chrome-50">
                @forelse ($quotations as $quote)
                    @php
                        $sb = [
                            'draft' => 'bg-chrome-200 text-chrome-700',
                            'sent' => 'bg-sky-100 text-sky-700',
                            'accepted' => 'bg-emerald-100 text-emerald-700',
                            'declined' => 'bg-red-100 text-red-700',
                            'converted' => 'bg-violet-100 text-violet-700',
                        ][$quote->status] ?? 'bg-chrome-200 text-chrome-700';
                    @endphp
                    <tr wire:key="lq-{{ $quote->id }}" class="hover:bg-chrome-50">
                        <td class="px-4 py-2 font-medium">
                            <a href="{{ url('/app/limousine/quotation/' . $quote->id) }}" wire:navigate
                               class="text-primary-700 hover:underline">{{ $quote->reference }}</a>
                        </td>
                        <td class="px-4 py-2 text-chrome-700">{{ $quote->customer?->name ?? '—' }}</td>
                        <td class="px-4 py-2 text-chrome-600">{{ $quote->valid_until?->isoFormat('MMM D, YYYY') ?? '—' }}</td>
                        <td class="px-4 py-2 text-end font-medium text-chrome-800">{{ \App\Erp\Views\ValueFormat::money($quote->fare) }}</td>
                        <td class="px-4 py-2">
                            <span class="rounded px-2 py-0.5 text-[11px] font-semibold uppercase {{ $sb }}">{{ __(ucfirst($quote->status)) }}</span>
                            @if ($quote->sent_at)
                                <span class="ms-1 text-[11px] text-chrome-400"
                                      title="{{ __('Sent to :email on :date', ['email' => $quote->sent_to, 'date' => $quote->sent_at->isoFormat('DD-MMM-YY HH:mm')]) }}">✓ {{ __('sent') }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2">
                            @php $act = 'inline-flex size-7 items-center justify-center rounded-lg transition'; @endphp
                            <div class="flex items-center gap-0.5">
                                <a href="{{ url('/app/limousine/quotation/' . $quote->id) }}" wire:navigate
                                   title="{{ __('Edit quotation') }}" aria-label="{{ __('Edit quotation') }}"
                                   class="{{ $act }} text-chrome-500 hover:bg-primary-50 hover:text-primary-700">
                                    <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z"/>
                                    </svg>
                                </a>

                                @if ($canWrite)
                                    <button type="button" wire:click="openSend({{ $quote->id }})"
                                            title="{{ $quote->sent_at ? __('Send the quotation again') : __('Email the quotation to the customer') }}"
                                            aria-label="{{ $quote->sent_at ? __('Send the quotation again') : __('Email the quotation to the customer') }}"
                                            class="{{ $act }} text-chrome-500 hover:bg-primary-50 hover:text-primary-700">
                                        <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75"/>
                                        </svg>
                                    </button>
                                @endif

                                {{-- Process: the quote becomes a booking. Gone once it
                                     already has one — converting twice would put the
                                     same job on the road under two references. --}}
                                @if ($canWrite && $quote->booking_id === null)
                                    <button type="button" wire:click="process({{ $quote->id }})"
                                            wire:confirm="{{ __('Turn this quotation into a booking?') }}"
                                            title="{{ __('Process into a booking') }}" aria-label="{{ __('Process into a booking') }}"
                                            class="{{ $act }} text-emerald-600 hover:bg-emerald-50">
                                        <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3"/>
                                        </svg>
                                    </button>
                                @elseif ($quote->booking_id !== null)
                                    <a href="{{ url('/app/limousine/booking/' . $quote->booking_id) }}" wire:navigate
                                       title="{{ __('Open the booking this became') }}" aria-label="{{ __('Open the booking this became') }}"
                                       class="{{ $act }} text-violet-600 hover:bg-violet-50">
                                        <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/>
                                        </svg>
                                    </a>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-10 text-center text-sm text-chrome-400">{{ __('No quotations found.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $quotations->links() }}</div>

    {{-- Send the quotation.
         Offered filled in from the customer and editable: a quote is approved
         by whoever holds the budget, who is often not the person who asked. --}}
    @if ($sending)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-chrome-900/50 p-4"
             wire:key="send-q-{{ $sending->id }}"
             x-on:keydown.escape.window="$wire.closeSend()">
            <div class="w-full max-w-md rounded-2xl bg-white p-5 shadow-pop sm:p-6" x-on:click.outside="$wire.closeSend()">
                <h2 class="text-base font-bold text-chrome-900">{{ __('Send the quotation') }}</h2>
                <p class="mt-1 text-sm text-chrome-500">
                    {{ $sending->reference }} · {{ $sending->customer?->name ?? __('No customer') }} ·
                    <span class="font-semibold text-chrome-800">{{ \App\Erp\Views\ValueFormat::money((float) $sending->fare) }}</span>
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
                        {{ __('Filled in from the customer — correct it here if the quotation should go elsewhere.') }}
                    </p>
                </div>

                <p class="mt-3 text-[11px] text-chrome-400">
                    {{ __('It goes out as a PDF listing every trip and the total.') }}
                </p>

                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" wire:click="closeSend" class="o-btn-ghost text-sm">{{ __('Cancel') }}</button>
                    <button type="button" wire:click="sendQuotation" wire:loading.attr="disabled" class="o-btn-primary text-sm">
                        {{ $sending->sent_at ? __('Send again') : __('Send') }}
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
