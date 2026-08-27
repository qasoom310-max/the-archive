<div class="mx-auto max-w-7xl p-4 sm:p-6">
    <x-page-header :title="__('Bookings')" :subtitle="__('Trip bookings.')" icon="calendar" accent="indigo">
        <x-slot:actions>
            <a href="{{ url('/app/limousine/booking/new') }}" wire:navigate class="o-btn-primary">
                <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10 5a1 1 0 0 1 1 1v3h3a1 1 0 1 1 0 2h-3v3a1 1 0 1 1-2 0v-3H6a1 1 0 1 1 0-2h3V6a1 1 0 0 1 1-1Z"/></svg>
                {{ __('New booking') }}
            </a>
        </x-slot:actions>
    </x-page-header>

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
        <div>
            <label class="mb-1 block text-xs font-medium text-chrome-500">{{ __('Pick-up from') }}</label>
            <input type="date" wire:model.live="from" class="o-input text-sm">
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium text-chrome-500">{{ __('Pick-up to') }}</label>
            <input type="date" wire:model.live="to" class="o-input text-sm">
        </div>
        @if ($from !== '' || $to !== '')
            <button wire:click="$set('from', ''); $set('to', '')" class="text-sm text-chrome-500 hover:underline">{{ __('Clear') }}</button>
        @endif
    </div>

    <div class="overflow-x-auto rounded-2xl bg-white shadow-sm ring-1 ring-chrome-900/[0.06]">
        <table class="w-full min-w-[720px] divide-y divide-chrome-100 text-sm">
            <thead class="bg-chrome-50 text-xs font-semibold uppercase tracking-wide text-chrome-500">
                <tr>
                    <th class="px-4 py-2 text-start">{{ __('Reference') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Customer') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Route') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Pick-up') }}</th>
                    <th class="px-4 py-2 text-end">{{ __('Fare') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Status') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Payment') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Car') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-chrome-50">
                {{-- One row per LEG: each is dispatched separately, with its own
                     reference, car and status. Payment belongs to the parent
                     booking, so every leg of a paid job reads "paid". --}}
                @forelse ($legs as $leg)
                    @php
                        $booking = $leg->legable;
                        $sb = [
                            'queue' => 'bg-amber-100 text-amber-700',
                            'confirmed' => 'bg-sky-100 text-sky-700',
                            'active' => 'bg-indigo-100 text-indigo-700',
                            'completed' => 'bg-emerald-100 text-emerald-700',
                            'cancelled' => 'bg-red-100 text-red-700',
                        ][$leg->status] ?? 'bg-chrome-200 text-chrome-700';
                        $next = [
                            'queue' => ['confirmed', __('Confirm')],
                            'confirmed' => ['active', __('Start trip')],
                            'active' => ['completed', __('Complete')],
                        ][$leg->status] ?? null;
                    @endphp
                    <tr wire:key="leg-{{ $leg->id }}" class="cursor-pointer hover:bg-chrome-50"
                        onclick="window.location='{{ url('/app/limousine/booking/' . $leg->legable_id) }}'">
                        <td class="px-4 py-2 font-medium text-chrome-800">
                            {{ $leg->reference ?? '—' }}
                            <span class="block text-[11px] font-normal text-chrome-400">{{ $booking?->reference }}</span>
                        </td>
                        <td class="px-4 py-2 text-chrome-700">{{ $booking?->customer?->name ?? '—' }}</td>
                        <td class="px-4 py-2 text-chrome-600">{{ $leg->from_location ?? '—' }}{{ $leg->to_location ? ' → ' . $leg->to_location : '' }}</td>
                        <td class="px-4 py-2 text-chrome-600">{{ $leg->start_at?->isoFormat('MMM D, h:mm A') ?? '—' }}</td>
                        <td class="px-4 py-2 text-end font-medium text-chrome-800">{{ \App\Erp\Views\ValueFormat::money($leg->net_amount) }}</td>
                        <td class="px-4 py-2" onclick="event.stopPropagation()">
                            <span class="rounded px-2 py-0.5 text-[11px] font-semibold uppercase {{ $sb }}">{{ __(ucfirst((string) $leg->status)) }}</span>
                            @if ($next !== null && $canAssign)
                                <button type="button" wire:click="advanceLeg({{ $leg->id }}, '{{ $next[0] }}')"
                                        class="ms-2 text-xs font-medium text-primary-700 hover:underline">{{ $next[1] }}</button>
                            @endif
                        </td>
                        {{-- Payment is the booking's, shared by all its legs. --}}
                        <td class="px-4 py-2"><span class="rounded px-2 py-0.5 text-[11px] font-semibold uppercase {{ $booking?->payment_status === 'paid' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' }}">{{ __(ucfirst((string) $booking?->payment_status)) }}</span></td>
                        {{-- stopPropagation so dispatching doesn't also follow the row link. --}}
                        <td class="px-4 py-2" onclick="event.stopPropagation()">
                            @if ($leg->vehicle !== null)
                                <span class="text-chrome-700">{{ $leg->vehicle }}</span>
                                @if ($canAssign)
                                    <button type="button" wire:click="openAssign({{ $leg->legable_id }})"
                                            class="ms-2 text-xs font-medium text-primary-700 hover:underline">{{ __('Change') }}</button>
                                @endif
                            @elseif ($canAssign)
                                <button type="button" wire:click="openAssign({{ $leg->legable_id }})"
                                        class="rounded-lg border border-chrome-200 px-2.5 py-1 text-xs font-medium text-chrome-600 transition hover:bg-chrome-50">
                                    {{ __('Assign car') }}
                                </button>
                            @else
                                <span class="text-chrome-400">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-10 text-center text-sm text-chrome-400">{{ __('No bookings found.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $legs->links() }}</div>

    {{-- ── Assign car ──
         One picker per leg: a booking can run several legs and they don't have
         to share a vehicle. Keyed by leg id, which is what saveAssign() matches
         on, so a re-ordered leg can never receive another leg's car. --}}
    @if ($assigningBooking !== null)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-chrome-900/50 p-4"
             wire:key="assign-{{ $assigningBooking->id }}"
             x-on:keydown.escape.window="$wire.closeAssign()">
            <div class="w-full max-w-lg rounded-2xl bg-white p-5 shadow-xl sm:p-6" x-on:click.outside="$wire.closeAssign()">
                <h2 class="text-sm font-semibold text-chrome-800">{{ __('Assign car') }}</h2>
                <p class="mt-1 text-xs text-chrome-500">{{ $assigningBooking->reference }} · {{ $assigningBooking->customer?->name }}</p>

                <div class="mt-4 space-y-4">
                    @forelse ($assigningBooking->legs as $n => $leg)
                        <div wire:key="assign-leg-{{ $leg->id }}">
                            <label class="mb-1 block text-sm font-medium text-chrome-700">
                                {{ __('Leg') }} {{ $n + 1 }}
                                <span class="font-normal text-chrome-500">
                                    — {{ $leg->from_location ?? '—' }}{{ $leg->to_location ? ' → ' . $leg->to_location : '' }}
                                </span>
                            </label>
                            <select wire:model="assignCars.{{ $leg->id }}" class="o-input w-full">
                                <option value="">{{ count($carOptions) ? __('— Select —') : __('No cars available') }}</option>
                                @foreach ($carOptions as $opt)<option value="{{ $opt['value'] }}">{{ $opt['label'] }}</option>@endforeach
                            </select>
                        </div>
                    @empty
                        <p class="text-sm text-chrome-500">{{ __('This booking has no trip legs yet.') }}</p>
                    @endforelse
                </div>

                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" wire:click="closeAssign" class="o-btn-ghost text-sm">{{ __('Close') }}</button>
                    <button type="button" wire:click="saveAssign" wire:loading.attr="disabled" class="o-btn-primary text-sm">{{ __('Save') }}</button>
                </div>
            </div>
        </div>
    @endif
</div>
