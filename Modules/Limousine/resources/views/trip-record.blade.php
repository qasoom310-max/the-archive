<div class="mx-auto max-w-4xl p-4 sm:p-6">
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold text-chrome-900">{{ __('Trip record') }} · {{ $leg->reference }}</h1>
            <p class="text-sm text-chrome-500">{{ __('What the driver recorded on his trip link: KM, fuel, time and place at the start and the end.') }}</p>
        </div>
        <a href="{{ url('/app/limousine/booking') }}" wire:navigate class="text-sm font-medium text-primary-700 hover:underline">{{ __('Back to bookings') }}</a>
    </div>

    @if ($link)
        <div class="mb-4 rounded-xl bg-white p-3 text-xs text-chrome-500 shadow-sm ring-1 ring-chrome-900/5">
            {{ __("Driver's trip link") }}: <a href="{{ $link }}" target="_blank" rel="noopener" class="break-all text-primary-700 underline">{{ \Illuminate\Support\Str::limit($link, 90) }}</a>
        </div>
    @endif

    @forelse ($runs as $run)
        <div class="mb-4 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5" data-trip-run>
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="font-semibold text-chrome-900">{{ $drivers[$run->driver_id] ?? __('Driver') . ' #' . $run->driver_id }}</h2>
                @if ($run->isEnded())
                    <span class="rounded bg-emerald-100 px-2 py-0.5 text-[11px] font-semibold uppercase text-emerald-700">{{ __('Finished') }}</span>
                @elseif ($run->isStarted())
                    <span class="rounded bg-sky-100 px-2 py-0.5 text-[11px] font-semibold uppercase text-sky-700">{{ __('On the trip') }}</span>
                @else
                    <span class="rounded bg-chrome-100 px-2 py-0.5 text-[11px] font-semibold uppercase text-chrome-600">{{ __('Not started') }}</span>
                @endif
                @if ($run->distance() !== null)
                    <span class="ms-auto text-sm font-semibold text-chrome-800">{{ number_format($run->distance()) }} km</span>
                @endif
            </div>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                @foreach (['start' => __('Start'), 'end' => __('End')] as $stage => $label)
                    @php
                        $at = $stage === 'start' ? $run->started_at : $run->ended_at;
                        $map = \Modules\Limousine\Models\LimoTripRun::mapUrl($run->getAttribute($stage . '_lat'), $run->getAttribute($stage . '_lng'));
                    @endphp
                    <div class="rounded-xl border border-chrome-200 p-3 text-sm" data-trip-stage="{{ $stage }}">
                        <p class="text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ $label }}</p>
                        <dl class="mt-2 space-y-1">
                            <div class="flex justify-between gap-2"><dt class="text-chrome-500">{{ __('Time') }}</dt><dd class="font-medium text-chrome-800">{{ $at?->isoFormat('DD-MMM-YY HH:mm') ?? '—' }}</dd></div>
                            <div class="flex justify-between gap-2"><dt class="text-chrome-500">KM</dt><dd class="font-medium tabular-nums text-chrome-800">{{ $run->getAttribute($stage . '_km') !== null ? number_format((int) $run->getAttribute($stage . '_km')) : '—' }}</dd></div>
                            <div class="flex justify-between gap-2"><dt class="text-chrome-500">{{ __('Fuel') }}</dt><dd class="font-medium text-chrome-800">{{ $at ? __(\Modules\Limousine\Models\LimoTripRun::fuelLabel($run->getAttribute($stage . '_fuel'))) : '—' }}</dd></div>
                            <div class="flex justify-between gap-2"><dt class="text-chrome-500">{{ __('Location') }}</dt>
                                <dd>@if ($map)<a href="{{ $map }}" target="_blank" rel="noopener" class="font-medium text-primary-700 underline">{{ __('Open map') }}</a>@else<span class="text-chrome-400">—</span>@endif</dd></div>
                        </dl>
                        @if ($run->getAttribute($stage . '_photo'))
                            <a href="{{ route('limousine.trip_run.photo', ['run' => $run->id, 'stage' => $stage]) }}" target="_blank" rel="noopener">
                                <img src="{{ route('limousine.trip_run.photo', ['run' => $run->id, 'stage' => $stage]) }}" alt="{{ __('Dashboard photo') }}" class="mt-3 max-h-56 w-full rounded-lg object-cover ring-1 ring-chrome-200" loading="lazy">
                            </a>
                        @endif
                    </div>
                @endforeach
            </div>

            @if (! empty($run->flags))
                <div class="mt-4 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900" data-trip-flags>
                    <p class="font-semibold">{{ __('Check') }}</p>
                    <ul class="mt-1 list-disc ps-5">
                        @foreach ($run->flags as $flag)<li>{{ $flag }}</li>@endforeach
                    </ul>
                </div>
            @endif
            @if ($run->customer_notified_at)
                <p class="mt-3 text-xs text-chrome-500">{{ __('Customer emailed that the driver is on the way at :time.', ['time' => $run->customer_notified_at->isoFormat('HH:mm')]) }}</p>
            @endif
        </div>
    @empty
        <div class="rounded-2xl bg-white p-8 text-center text-sm text-chrome-500 shadow-sm ring-1 ring-chrome-900/5">
            {{ __('The driver has not opened or started this trip yet.') }}
        </div>
    @endforelse
</div>
