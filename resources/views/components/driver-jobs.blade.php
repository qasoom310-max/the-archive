{{--
    A driver's papers and their working life, both businesses in one list.

    The licence sits above the history because it decides whether the rest can
    continue. The history itself is its own Livewire component — it searches and
    pages, since four jobs a day is a hundred and twenty a month and a whole
    career on one screen is a scroll with no bottom.

        <x-driver-jobs :driver="$driver" />
--}}
@props(['driver'])

@if ($driver)
    @php
        $expired = $driver->licenceExpired();
        $expiringSoon = $driver->licenceExpiringSoon();
    @endphp

    @if ($expired)
        <div class="mt-5 rounded-2xl bg-red-50 p-4 text-sm ring-1 ring-red-200">
            <p class="font-semibold text-red-800">{{ __('Licence expired') }}</p>
            <p class="mt-0.5 text-red-700">
                {{ __('Expired on :date. This driver cannot be given a trip until the licence is renewed above.', [
                    'date' => $driver->license_expiry?->isoFormat('DD-MMM-YYYY'),
                ]) }}
            </p>
        </div>
    @elseif ($expiringSoon)
        <div class="mt-5 rounded-2xl bg-amber-50 p-4 text-sm ring-1 ring-amber-200">
            <p class="font-semibold text-amber-800">{{ __('Licence expires soon') }}</p>
            <p class="mt-0.5 text-amber-700">
                {{ __('Valid until :date. After that this driver cannot be given a trip.', [
                    'date' => $driver->license_expiry?->isoFormat('DD-MMM-YYYY'),
                ]) }}
            </p>
        </div>
    @endif

    <livewire:modules.rental.livewire.driver-jobs :driver-id="(int) $driver->id" :key="'driver-jobs-'.$driver->id" />
@endif
