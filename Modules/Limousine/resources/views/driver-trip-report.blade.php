@php
    $badge = [
        'done' => ['bg-emerald-100 text-emerald-700', __('Recorded')],
        'office' => ['bg-amber-100 text-amber-700', __('Ended by office')],
        'running' => ['bg-sky-100 text-sky-700', __('On the trip')],
        'missed' => ['bg-red-100 text-red-700', __('Not started')],
        'upcoming' => ['bg-chrome-100 text-chrome-600', __('Coming')],
    ];
@endphp
<div class="mx-auto max-w-5xl p-4 sm:p-6">
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold text-chrome-900">{{ __('Driver trip records') }}</h1>
            <p class="text-sm text-chrome-500">{{ __('How each driver used his trip link this month: recorded properly, ended by the office, or never started.') }}</p>
        </div>
        <div class="flex items-center gap-2">
            <button type="button" wire:click="shiftMonth(-1)" class="o-btn-ghost px-2 ring-1 ring-chrome-200" aria-label="{{ __('Previous month') }}">‹</button>
            <span class="min-w-32 text-center text-sm font-semibold text-chrome-800">{{ $monthLabel }}</span>
            <button type="button" wire:click="shiftMonth(1)" class="o-btn-ghost px-2 ring-1 ring-chrome-200" aria-label="{{ __('Next month') }}">›</button>
        </div>
    </div>

    <div class="overflow-x-auto rounded-2xl bg-white shadow-sm ring-1 ring-chrome-900/[0.06]">
        <table class="w-full min-w-[720px] divide-y divide-chrome-100 text-sm" data-driver-trip-report>
            <thead class="bg-chrome-50 text-xs font-semibold uppercase tracking-wide text-chrome-500">
                <tr>
                    <th class="px-4 py-2.5 text-start">{{ __('Driver') }}</th>
                    <th class="px-3 py-2.5 text-end">{{ __('Trips') }}</th>
                    <th class="px-3 py-2.5 text-end">{{ __('Recorded') }}</th>
                    <th class="px-3 py-2.5 text-end">{{ __('Not started') }}</th>
                    <th class="px-3 py-2.5 text-end">{{ __('Ended by office') }}</th>
                    <th class="px-3 py-2.5 text-end">{{ __('To check') }}</th>
                    <th class="px-3 py-2.5 text-end">{{ __('Score') }}</th>
                    <th class="px-4 py-2.5 text-end">KM</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-chrome-100">
                @forelse ($rows as $r)
                    @php
                        $due = $r['trips'] - $r['upcoming'] - $r['running'];
                        $score = $due > 0 ? (int) round($r['done'] / $due * 100) : null;
                    @endphp
                    <tr class="cursor-pointer hover:bg-chrome-50" wire:click="toggleDriver({{ $r['id'] }})" wire:key="d-{{ $r['id'] }}" data-driver-row>
                        <td class="px-4 py-2.5 font-medium text-chrome-800">{{ $r['name'] }}</td>
                        <td class="px-3 py-2.5 text-end tabular-nums">{{ $r['trips'] }}</td>
                        <td class="px-3 py-2.5 text-end tabular-nums text-emerald-700">{{ $r['done'] }}</td>
                        <td class="px-3 py-2.5 text-end tabular-nums {{ $r['missed'] > 0 ? 'font-semibold text-red-600' : 'text-chrome-400' }}">{{ $r['missed'] }}</td>
                        <td class="px-3 py-2.5 text-end tabular-nums {{ $r['office'] > 0 ? 'font-semibold text-amber-700' : 'text-chrome-400' }}">{{ $r['office'] }}</td>
                        <td class="px-3 py-2.5 text-end tabular-nums {{ $r['checks'] > 0 ? 'text-amber-700' : 'text-chrome-400' }}">{{ $r['checks'] }}</td>
                        <td class="px-3 py-2.5 text-end font-semibold tabular-nums {{ $score === null ? 'text-chrome-400' : ($score >= 90 ? 'text-emerald-700' : ($score >= 70 ? 'text-amber-700' : 'text-red-600')) }}">{{ $score === null ? '—' : $score . '%' }}</td>
                        <td class="px-4 py-2.5 text-end tabular-nums text-chrome-600">{{ number_format($r['km']) }}</td>
                    </tr>
                    @if ($openDriver === $r['id'])
                        <tr wire:key="dl-{{ $r['id'] }}">
                            <td colspan="8" class="bg-chrome-50/60 px-4 py-3">
                                <ul class="divide-y divide-chrome-100 text-sm">
                                    @foreach ($r['list'] as $item)
                                        <li class="flex flex-wrap items-center gap-3 py-1.5">
                                            <span class="w-28 text-chrome-500">{{ $item['leg']->start_at?->isoFormat('DD-MMM HH:mm') }}</span>
                                            <a href="{{ url('/app/limousine/trip-record/' . $item['leg']->id) }}" wire:navigate class="font-medium text-primary-700 hover:underline">{{ $item['leg']->reference }}</a>
                                            <span class="rounded px-2 py-0.5 text-[11px] font-semibold uppercase {{ $badge[$item['state']][0] }}">{{ $badge[$item['state']][1] }}</span>
                                            @if ($item['flagged'])<span class="text-xs text-amber-700">⚠ {{ __('To check') }}</span>@endif
                                            @if ($item['run']?->distance() !== null)<span class="ms-auto tabular-nums text-chrome-600">{{ number_format((int) $item['run']->distance()) }} km</span>@endif
                                        </li>
                                    @endforeach
                                </ul>
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr><td colspan="8" class="px-4 py-10 text-center text-sm text-chrome-400">{{ __('No trips with a driver this month.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <p class="mt-3 text-xs text-chrome-500">{{ __('Score = trips the driver recorded himself ÷ trips that are over. Trips before 25 Nov 2026 are not counted: the link did not exist yet.') }}</p>
</div>
