@php
    $money = fn (float $v): string => \App\Erp\Views\ValueFormat::money($v);
    $shade = [
        0 => 'bg-chrome-100 text-chrome-400',
        1 => 'bg-emerald-50 text-emerald-700',
        2 => 'bg-emerald-100 text-emerald-800',
        3 => 'bg-emerald-200 text-emerald-900',
        4 => 'bg-emerald-400 text-white',
        5 => 'bg-emerald-500 text-white',
        6 => 'bg-emerald-700 text-white',
    ];
    $kindBar = [
        'islamic' => 'border-t-2 border-amber-500',
        'national' => 'border-t-2 border-sky-500',
        'custom' => 'border-t-2 border-violet-500',
    ];
    $statusTone = [
        'overdue' => 'bg-red-100 text-red-700',
        'now' => 'bg-amber-100 text-amber-800',
        'live' => 'bg-emerald-100 text-emerald-700',
        'upcoming' => 'bg-chrome-100 text-chrome-600',
        'passed' => 'bg-chrome-100 text-chrome-400',
    ];
    $statusLabel = [
        'overdue' => __('Ads overdue'),
        'now' => __('Launch ads now'),
        'live' => __('Happening now'),
        'upcoming' => __('Upcoming'),
        'passed' => __('Passed'),
    ];
@endphp
<div class="mx-auto max-w-7xl p-4 sm:p-6">
    <x-page-header :title="__('Ad calendar')" :subtitle="__(':company — when to advertise, from what actually sold.', ['company' => $companyName])" icon="calendar" accent="amber">
        <x-slot:actions>
            <button type="button" wire:click="openEvent" class="o-btn-primary">{{ __('Add event') }}</button>
        </x-slot:actions>
    </x-page-header>

    @if (session('adcal_toast'))
        <div class="mb-4 rounded-lg bg-emerald-50 px-4 py-2.5 text-sm font-medium text-emerald-700 ring-1 ring-emerald-100">{{ session('adcal_toast') }}</div>
    @endif

    @if ($sources === [])
        <div class="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800 ring-1 ring-amber-100">
            {{ __('This database runs no app with sales to read (Rent A Car, Limousine or Point of Sale).') }}
        </div>
    @else

    {{-- The year in four numbers --}}
    <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5">
            <div class="text-sm font-medium text-chrome-600">{{ __('Last 12 months') }}</div>
            <div class="mt-2 text-2xl font-bold text-chrome-800">{{ $money($heatmap['total']) }}</div>
            <div class="mt-1 text-xs text-chrome-400">
                @foreach ($sources as $source)
                    <span class="me-2">{{ $sourceLabels[$source] }} {{ $money($heatmap['bySource'][$source]) }}</span>
                @endforeach
            </div>
        </div>
        <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5">
            <div class="text-sm font-medium text-chrome-600">{{ __('A normal week') }}</div>
            <div class="mt-2 text-2xl font-bold text-chrome-800">{{ $money($heatmap['baseline']) }}</div>
            <div class="mt-1 text-xs text-chrome-400">{{ __('Median week — what "busy" is measured against') }}</div>
        </div>
        <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5">
            <div class="text-sm font-medium text-chrome-600">{{ __('Best month') }}</div>
            <div class="mt-2 text-2xl font-bold text-emerald-700">{{ $heatmap['bestMonth'] ? $money($heatmap['bestMonth']['total']) : '—' }}</div>
            <div class="mt-1 text-xs text-chrome-400">{{ $heatmap['bestMonth']['label'] ?? __('No sales on record yet') }}</div>
        </div>
        <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5">
            <div class="text-sm font-medium text-chrome-600">{{ __('Slowest month') }}</div>
            <div class="mt-2 text-2xl font-bold text-red-600">{{ $heatmap['worstMonth'] ? $money($heatmap['worstMonth']['total']) : '—' }}</div>
            <div class="mt-1 text-xs text-chrome-400">{{ $heatmap['worstMonth']['label'] ?? __('No sales on record yet') }}</div>
        </div>
    </div>

    {{-- The plan: every window ahead, and the date the ads must be live by --}}
    <div class="mb-6 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/[0.06]">
        <div class="mb-3">
            <h2 class="text-sm font-semibold text-chrome-800">{{ __('Ad plan — next 4 months') }}</h2>
            <p class="text-xs text-chrome-400">{{ __('Last year\'s figure for each window is lined up by Islamic date, so Eid is compared with Eid. "Launch by" is the window\'s start minus your lead days.') }}</p>
        </div>

        @if ($plan === [])
            <p class="py-6 text-center text-sm text-chrome-400">{{ __('No selling windows in the next four months for the markets you track.') }}</p>
        @else
            <div class="grid gap-3 md:grid-cols-2">
                @foreach ($plan as $item)
                    <div wire:key="plan-{{ $item['key'] }}-{{ $item['start'] }}" class="rounded-xl border border-chrome-200 p-4 {{ $item['status'] === 'overdue' ? 'border-red-200 bg-red-50/40' : '' }}">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-1.5">
                                    @if ($item['flag'] !== '')
                                        <span class="text-base leading-none">{{ $item['flag'] }}</span>
                                    @elseif ($item['kind'] === 'islamic')
                                        <span class="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-amber-800">{{ __('Islamic') }}</span>
                                    @else
                                        <span class="rounded bg-violet-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-violet-700">{{ __('Yours') }}</span>
                                    @endif
                                    <span class="font-semibold text-chrome-800">{{ $item['kind'] === 'custom' ? $item['label'] : __($item['label']) }}</span>
                                </div>
                                <div class="mt-0.5 text-xs text-chrome-500">
                                    {{ \Carbon\CarbonImmutable::parse($item['start'])->isoFormat('DD MMM') }}
                                    @if ($item['days'] > 1) – {{ \Carbon\CarbonImmutable::parse($item['end'])->isoFormat('DD MMM') }} @endif
                                    · {{ trans_choice(':count day|:count days', $item['days'], ['count' => $item['days']]) }}
                                    @if ($item['daysUntil'] > 0) · {{ __('in :n days', ['n' => $item['daysUntil']]) }} @endif
                                </div>
                            </div>
                            <span class="shrink-0 rounded px-2 py-0.5 text-[11px] font-semibold uppercase {{ $statusTone[$item['status']] }}">{{ $statusLabel[$item['status']] }}</span>
                        </div>

                        <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                            @if ($item['noData'])
                                <span class="text-chrome-400">{{ __('No sales on record for this window last year.') }}</span>
                            @else
                                <span class="text-chrome-700">{{ __('Last year') }}: <span class="font-semibold">{{ $money((float) $item['lastYearRevenue']) }}</span></span>
                                @if ($item['uplift'] !== null)
                                    @php $pct = (int) round($item['uplift'] * 100); @endphp
                                    <span class="rounded px-1.5 py-0.5 text-xs font-semibold {{ $pct >= 25 ? 'bg-emerald-100 text-emerald-700' : ($pct <= -25 ? 'bg-red-100 text-red-700' : 'bg-chrome-100 text-chrome-600') }}">
                                        {{ $pct >= 0 ? '+' : '' }}{{ $pct }}% {{ __('vs a normal week') }}
                                    </span>
                                @endif
                            @endif
                        </div>

                        <div class="mt-3 grid gap-1 text-xs sm:grid-cols-2">
                            @foreach ($sources as $source)
                                @php $l = $item['launch'][$source]; @endphp
                                <div class="flex items-center justify-between rounded-lg bg-chrome-50 px-2.5 py-1.5">
                                    <span class="text-chrome-600">{{ $sourceLabels[$source] }}</span>
                                    <span class="font-semibold {{ $l['status'] === 'overdue' ? 'text-red-600' : ($l['status'] === 'now' ? 'text-amber-700' : 'text-chrome-800') }}">
                                        {{ __('Launch by') }} {{ \Carbon\CarbonImmutable::parse($l['by'])->isoFormat('DD MMM') }}
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- Last year, day by day --}}
    <div class="mb-6 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/[0.06]">
        <div class="mb-3 flex flex-wrap items-end justify-between gap-2">
            <div>
                <h2 class="text-sm font-semibold text-chrome-800">{{ __('Last 12 months, day by day') }}</h2>
                <p class="text-xs text-chrome-400">{{ __('Darker is busier. Hatched days are before your first sale on record, or a closure — no data, not no demand. Bahrain weekends (Fri–Sat) are underlined.') }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-3 text-[11px] text-chrome-500">
                <span class="flex items-center gap-1"><span class="inline-block h-3 w-3 border-t-2 border-amber-500 bg-chrome-100"></span>{{ __('Islamic') }}</span>
                <span class="flex items-center gap-1"><span class="inline-block h-3 w-3 border-t-2 border-sky-500 bg-chrome-100"></span>{{ __('National day') }}</span>
                <span class="flex items-center gap-1"><span class="inline-block h-3 w-3 border-t-2 border-violet-500 bg-chrome-100"></span>{{ __('Yours') }}</span>
            </div>
        </div>

        <div class="overflow-x-auto" dir="ltr">
            <table class="border-separate border-spacing-0.5 text-[10px]">
                <thead>
                    <tr>
                        <th class="pe-2 text-start text-xs font-medium text-chrome-500"></th>
                        @for ($i = 1; $i <= 31; $i++)
                            <th class="w-7 text-center font-medium text-chrome-400">{{ $i }}</th>
                        @endfor
                        <th class="ps-2 text-end text-xs font-medium text-chrome-500">{{ __('Month') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($heatmap['months'] as $month)
                        <tr wire:key="hm-{{ $month['key'] }}">
                            <th class="whitespace-nowrap pe-2 text-start text-xs font-semibold text-chrome-700">{{ $month['label'] }}</th>
                            @foreach ($month['days'] as $day)
                                @php
                                    $cls = $day['noData']
                                        ? 'bg-chrome-50 text-chrome-300 border border-dashed border-chrome-200'
                                        : $shade[$day['level']];
                                    foreach ($day['eventKinds'] as $k) { $cls .= ' ' . ($kindBar[$k] ?? ''); }
                                    if ($day['weekend'] && ! $day['noData']) { $cls .= ' border-b-2 border-chrome-400'; }
                                    $tip = \Carbon\CarbonImmutable::parse($day['date'])->isoFormat('ddd DD MMM YYYY') . ' · '
                                        . ($day['noData'] ? __('No data') : $money($day['total']))
                                        . ($day['events'] !== [] ? ' · ' . implode(', ', $day['events']) : '');
                                @endphp
                                <td class="h-7 w-7 rounded text-center align-middle {{ $cls }}" title="{{ $tip }}">{{ $day['level'] >= 4 ? $day['day'] : '' }}</td>
                            @endforeach
                            @for ($i = count($month['days']); $i < 31; $i++)
                                <td class="h-7 w-7"></td>
                            @endfor
                            <td class="whitespace-nowrap ps-2 text-end text-xs font-semibold text-chrome-700">{{ $money($month['total']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        {{-- Peaks and troughs nobody has named yet --}}
        <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/[0.06]">
            <h2 class="text-sm font-semibold text-chrome-800">{{ __('Weeks that need a name') }}</h2>
            <p class="mb-3 text-xs text-chrome-400">{{ __('Weeks last year far above or below normal with no known window on them. Name them and they become part of next year\'s plan.') }}</p>

            @if ($unnamed === [])
                <p class="py-4 text-center text-sm text-chrome-400">{{ __('Every unusual week last year is already explained by a known window.') }}</p>
            @else
                <ul class="divide-y divide-chrome-100 text-sm">
                    @foreach ($unnamed as $week)
                        <li wire:key="un-{{ $week['start'] }}" class="flex flex-wrap items-center justify-between gap-2 py-2">
                            <div>
                                <span class="rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase {{ $week['type'] === 'peak' ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700' }}">{{ $week['type'] === 'peak' ? __('Peak') : __('Trough') }}</span>
                                <span class="ms-1 text-chrome-700">{{ \Carbon\CarbonImmutable::parse($week['start'])->isoFormat('DD MMM') }} – {{ \Carbon\CarbonImmutable::parse($week['end'])->isoFormat('DD MMM YYYY') }}</span>
                                <span class="ms-1 text-xs text-chrome-500">{{ $money($week['total']) }} · {{ $week['ratio'] }}×</span>
                            </div>
                            <button type="button" wire:click="openEvent(null, '{{ $week['start'] }}', '{{ $week['end'] }}')" class="text-xs font-medium text-primary-700 hover:underline">{{ __('Name it') }}</button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        {{-- The rules for this database --}}
        <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/[0.06]">
            <h2 class="text-sm font-semibold text-chrome-800">{{ __('Rules for this database') }}</h2>
            <p class="mb-3 text-xs text-chrome-400">{{ __('How far ahead your customers decide, and whose holidays bring you business. Each database keeps its own.') }}</p>

            <form wire:submit="saveSettings" class="space-y-4">
                <div>
                    <div class="mb-1 text-xs font-medium text-chrome-500">{{ __('Lead days — how long before a window the ads must be live') }}</div>
                    <div class="grid gap-2 sm:grid-cols-3">
                        @foreach ($sources as $source)
                            <label class="block">
                                <span class="text-xs text-chrome-600">{{ $sourceLabels[$source] }}</span>
                                <input type="number" min="0" max="365" wire:model="leadDays.{{ $source }}" class="o-input mt-1 w-full text-sm">
                            </label>
                        @endforeach
                    </div>
                    @error('leadDays.*') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <div class="mb-1 text-xs font-medium text-chrome-500">{{ __('Markets that visit you — their holidays show on the calendar') }}</div>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($countries as $code => [$label, $flag])
                            <label class="flex cursor-pointer items-center gap-1.5 rounded-lg border border-chrome-200 px-2.5 py-1.5 text-sm has-[:checked]:border-primary-400 has-[:checked]:bg-primary-50">
                                <input type="checkbox" value="{{ $code }}" wire:model="markets" class="size-4 rounded border-chrome-300 text-primary-600 focus:ring-primary-500">
                                <span>{{ $flag }}</span>
                                <span class="text-chrome-700">{{ __($label) }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('markets') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="flex justify-end">
                    <button type="submit" wire:loading.attr="disabled" class="o-btn-primary text-sm">{{ __('Save rules') }}</button>
                </div>
            </form>
        </div>
    </div>

    {{-- The owner's own windows --}}
    <div class="mt-6 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/[0.06]">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <div>
                <h2 class="text-sm font-semibold text-chrome-800">{{ __('Your events') }}</h2>
                <p class="text-xs text-chrome-400">{{ __('Seasons only you know — F1 weekend, wedding season, a closure. A closure is treated as no data, never as a slow week.') }}</p>
            </div>
            <button type="button" wire:click="openEvent" class="o-btn-ghost text-sm">{{ __('Add event') }}</button>
        </div>

        @if ($customEvents->isEmpty())
            <p class="py-4 text-center text-sm text-chrome-400">{{ __('Nothing added yet.') }}</p>
        @else
            <ul class="divide-y divide-chrome-100 text-sm">
                @foreach ($customEvents as $event)
                    <li wire:key="ev-{{ $event->id }}" class="flex flex-wrap items-center justify-between gap-2 py-2">
                        <div class="min-w-0">
                            <span class="rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase {{ $event->isClosed() ? 'bg-chrome-200 text-chrome-600' : 'bg-violet-100 text-violet-700' }}">{{ $event->isClosed() ? __('Closed') : __('Season') }}</span>
                            <span class="ms-1 font-medium text-chrome-800">{{ $event->name }}</span>
                            <span class="ms-1 text-xs text-chrome-500">
                                {{ $event->start_date->isoFormat('DD MMM YYYY') }} – {{ $event->end_date->isoFormat('DD MMM YYYY') }}
                                @if ($event->recurs) · {{ __('every year') }} @endif
                            </span>
                        </div>
                        <div class="flex items-center gap-3 text-xs">
                            <button type="button" wire:click="openEvent({{ $event->id }})" class="font-medium text-primary-700 hover:underline">{{ __('Edit') }}</button>
                            <button type="button" wire:click="deleteEvent({{ $event->id }})" wire:confirm="{{ __('Remove this event?') }}" class="font-medium text-red-600 hover:underline">{{ __('Remove') }}</button>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    @endif

    {{-- Add / edit an event --}}
    @if ($addingEvent)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-chrome-900/50 p-4" x-on:keydown.escape.window="$wire.closeEvent()">
            <div class="w-full max-w-md rounded-2xl bg-white p-5 shadow-xl sm:p-6" x-on:click.outside="$wire.closeEvent()">
                <h2 class="text-sm font-semibold text-chrome-800">{{ $editingId ? __('Edit event') : __('Add event') }}</h2>

                <div class="mt-4 space-y-4">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Name') }}</label>
                        <input type="text" wire:model="eventName" class="o-input w-full" placeholder="{{ __('e.g. F1 weekend, wedding season') }}">
                        @error('eventName') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('From') }}</label>
                            <x-date-field wire:model="eventStart" class="o-input w-full" />
                            @error('eventStart') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('To') }}</label>
                            <x-date-field wire:model="eventEnd" class="o-input w-full" />
                            @error('eventEnd') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('What is it?') }}</label>
                        <select wire:model="eventKind" class="o-input w-full">
                            <option value="custom">{{ __('A selling season (worth advertising into)') }}</option>
                            <option value="closed">{{ __('A closure (we were not operating)') }}</option>
                        </select>
                    </div>
                    <label class="flex items-center gap-2 text-sm text-chrome-700">
                        <input type="checkbox" wire:model="eventRecurs" class="size-4 rounded border-chrome-300 text-primary-600 focus:ring-primary-500">
                        {{ __('Repeats on the same dates every year') }}
                    </label>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Notes') }}</label>
                        <input type="text" wire:model="eventNotes" class="o-input w-full">
                    </div>
                </div>

                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" wire:click="closeEvent" class="o-btn-ghost text-sm">{{ __('Close') }}</button>
                    <button type="button" wire:click="saveEvent" wire:loading.attr="disabled" class="o-btn-primary text-sm">{{ __('Save') }}</button>
                </div>
            </div>
        </div>
    @endif
</div>
