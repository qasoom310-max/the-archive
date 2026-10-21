{{--
    Outside drivers: each commission driver, then every trip they drove in the
    period with the commission agreed for it. Built by OutsideDriverPayments.
--}}
@php
    $money = static fn (float $v): string => \App\Erp\Views\ValueFormat::money($v);
    $statusLabels = [
        'queue' => __('Queue'), 'confirmed' => __('Confirmed'), 'active' => __('Active'),
        'completed' => __('Completed'), 'cancelled' => __('Cancelled'),
    ];
@endphp

<div class="mx-auto max-w-[1400px] px-4 py-6 sm:px-6">

    <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-chrome-900">{{ __('Outside drivers') }}</h1>
            <p class="mt-1 text-sm text-chrome-500">{{ __('Commission drivers and what each is paid, trip by trip.') }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-2 print:hidden">
            <a href="{{ url('/app/limousine/driver') }}" wire:navigate class="o-btn-ghost text-sm">{{ __('Manage drivers') }}</a>
            <button type="button" onclick="window.print()" class="o-btn-ghost text-sm">{{ __('Print') }}</button>
        </div>
    </div>

    {{-- Period: a month with arrows, or any From/To. --}}
    <div class="mb-5 flex flex-wrap items-end gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/5 print:hidden">
        <div class="flex items-center gap-1">
            <button type="button" wire:click="shiftMonth(-1)" class="rounded-lg px-2 py-1.5 text-chrome-500 hover:bg-chrome-100" aria-label="{{ __('Previous month') }}">
                <svg class="size-4 rtl:rotate-180" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M11.78 5.22a.75.75 0 0 1 0 1.06L8.06 10l3.72 3.72a.75.75 0 1 1-1.06 1.06l-4.25-4.25a.75.75 0 0 1 0-1.06l4.25-4.25a.75.75 0 0 1 1.06 0Z" clip-rule="evenodd"/></svg>
            </button>
            <span class="min-w-32 text-center text-sm font-semibold text-chrome-800" data-period>
                @if ($isMonth)
                    {{ $periodFrom->translatedFormat('F Y') }}
                @else
                    {{ $periodFrom->format('j-M-Y') }} – {{ $periodTo->format('j-M-Y') }}
                @endif
            </span>
            <button type="button" wire:click="shiftMonth(1)" class="rounded-lg px-2 py-1.5 text-chrome-500 hover:bg-chrome-100" aria-label="{{ __('Next month') }}">
                <svg class="size-4 rtl:rotate-180" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8.22 5.22a.75.75 0 0 1 1.06 0l4.25 4.25a.75.75 0 0 1 0 1.06l-4.25 4.25a.75.75 0 0 1-1.06-1.06L11.94 10 8.22 6.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd"/></svg>
            </button>
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium text-chrome-500">{{ __('From') }}</label>
            <input type="date" wire:model.live="from" class="o-input">
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium text-chrome-500">{{ __('To') }}</label>
            <input type="date" wire:model.live="to" class="o-input">
        </div>
        <div class="min-w-48 flex-1">
            <label class="mb-1 block text-xs font-medium text-chrome-500">{{ __('Driver') }}</label>
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Search a driver…') }}" class="o-input w-full">
        </div>
    </div>

    {{-- Totals for the period. --}}
    <div class="mb-6 grid gap-4 sm:grid-cols-3">
        <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5">
            <div class="text-[11px] font-bold uppercase tracking-wide text-chrome-400">{{ __('To pay') }}</div>
            <div class="mt-2 text-2xl font-bold tracking-tight text-chrome-900" data-grand-total>{{ $money($report['total']) }}</div>
        </div>
        <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5">
            <div class="text-[11px] font-bold uppercase tracking-wide text-chrome-400">{{ __('Trips') }}</div>
            <div class="mt-2 text-2xl font-bold tracking-tight text-chrome-900">{{ $report['trips'] }}</div>
        </div>
        <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5">
            <div class="text-[11px] font-bold uppercase tracking-wide text-chrome-400">{{ __('Trips with no amount') }}</div>
            <div class="mt-2 text-2xl font-bold tracking-tight {{ $report['missing'] > 0 ? 'text-amber-600' : 'text-chrome-900' }}">{{ $report['missing'] }}</div>
        </div>
    </div>

    @forelse ($report['drivers'] as $driver)
        <section class="mb-5 overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-chrome-900/5 print:break-inside-avoid" data-driver="{{ $driver['id'] }}">
            <header class="flex flex-wrap items-center justify-between gap-3 border-b border-chrome-100 px-5 py-4">
                <div class="flex items-center gap-3">
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-primary-400 text-sm font-bold text-chrome-900">
                        {{ mb_strtoupper(mb_substr($driver['name'], 0, 1)) }}
                    </span>
                    <div>
                        <h2 class="text-base font-semibold text-chrome-900">{{ $driver['name'] }}</h2>
                        <p class="text-xs text-chrome-500">
                            @if ($driver['phone'] !== '') {{ $driver['phone'] }} · @endif
                            @if ($driver['commission'])
                                {{ $driver['usual'] !== null ? __('Usual commission :amount', ['amount' => $money($driver['usual'])]) : __('No usual commission set') }}
                            @else
                                {{ __('Company driver now') }}
                            @endif
                        </p>
                    </div>
                </div>
                <div class="text-end">
                    <div class="text-[11px] font-bold uppercase tracking-wide text-chrome-400">{{ __(':count trips', ['count' => count($driver['trips'])]) }}</div>
                    <div class="text-lg font-bold text-chrome-900" data-driver-total>{{ $money($driver['total']) }}</div>
                    @if ($driver['missing'] > 0)
                        <div class="text-xs font-medium text-amber-600">{{ __(':count with no amount', ['count' => $driver['missing']]) }}</div>
                    @endif
                </div>
            </header>

            @if ($driver['trips'] === [])
                <p class="px-5 py-4 text-sm text-chrome-400">{{ __('No trips in this period.') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-chrome-50 text-xs font-semibold uppercase tracking-wide text-chrome-500">
                            <tr>
                                <th class="px-4 py-2 text-start">{{ __('Date') }}</th>
                                <th class="px-4 py-2 text-start">{{ __('Trip') }}</th>
                                <th class="px-4 py-2 text-start">{{ __('Customer') }}</th>
                                <th class="px-4 py-2 text-start">{{ __('Route') }}</th>
                                <th class="px-4 py-2 text-start">{{ __('Car') }}</th>
                                <th class="px-4 py-2 text-start">{{ __('Status') }}</th>
                                <th class="px-4 py-2 text-end">{{ __('Commission') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-chrome-50">
                            @foreach ($driver['trips'] as $trip)
                                <tr>
                                    <td class="whitespace-nowrap px-4 py-2 text-chrome-700">{{ $trip['date'] }}</td>
                                    <td class="whitespace-nowrap px-4 py-2">
                                        <div class="font-medium text-chrome-900">{{ $trip['reference'] !== '' ? $trip['reference'] : '—' }}</div>
                                        @if ($trip['booking_id'] !== null)
                                            <a href="{{ url('/app/limousine/booking/'.$trip['booking_id']) }}" wire:navigate class="text-xs text-primary-700 hover:underline">{{ $trip['booking'] }}</a>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2 text-chrome-700">{{ $trip['customer'] !== '' ? $trip['customer'] : '—' }}</td>
                                    <td class="px-4 py-2 text-chrome-700">{{ $trip['route'] }}</td>
                                    <td class="px-4 py-2 text-chrome-700">{{ $trip['car'] !== '' ? $trip['car'] : '—' }}</td>
                                    <td class="whitespace-nowrap px-4 py-2 text-chrome-700">{{ $statusLabels[$trip['status']] ?? $trip['status'] }}</td>
                                    <td class="whitespace-nowrap px-4 py-2 text-end font-semibold {{ $trip['amount'] === null ? 'text-amber-600' : 'text-chrome-900' }}">
                                        {{ $trip['amount'] !== null ? $money($trip['amount']) : __('Not set') }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="bg-chrome-50">
                                <td colspan="6" class="px-4 py-2 text-end text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Total') }}</td>
                                <td class="whitespace-nowrap px-4 py-2 text-end font-bold text-chrome-900">{{ $money($driver['total']) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        </section>
    @empty
        <div class="rounded-2xl bg-white p-10 text-center shadow-sm ring-1 ring-chrome-900/5">
            <p class="text-sm text-chrome-500">{{ __('No outside drivers yet. A driver becomes one when they are set to commission, or when a commission is entered while assigning them a trip.') }}</p>
        </div>
    @endforelse
</div>
