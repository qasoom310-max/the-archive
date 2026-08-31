{{--
    A driver's working life, both businesses in one list.

    The office does not ask "what did they do in Limousine?" — they ask what
    this person has been doing. So trips and rentals are the same table here,
    newest first, each row saying what it was, when, who gave it out and which
    car went with it.

        <x-driver-jobs :driver="$driver" />
--}}
@props(['driver'])

@php
    $jobs = $driver ? app(\Modules\Rental\Services\DriverJobHistory::class)->for((int) $driver->id) : [];
    $expired = $driver?->licenceExpired() ?? false;
    $expiringSoon = $driver?->licenceExpiringSoon() ?? false;
@endphp

@if ($driver)
    {{-- The licence first, because it decides whether the rest can continue. --}}
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

    <div class="mt-5 overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-chrome-900/[0.06]">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-chrome-100 px-5 py-3">
            <h2 class="text-sm font-semibold text-chrome-800">{{ __('Job history') }}</h2>
            <span class="text-xs text-chrome-500">
                {{ trans_choice('{0}No jobs yet|{1}:count job|[2,*]:count jobs', count($jobs), ['count' => count($jobs)]) }}
            </span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full divide-y divide-chrome-100 text-sm">
                <thead class="bg-chrome-50 text-xs font-semibold uppercase tracking-wide text-chrome-500">
                    <tr>
                        <th class="px-4 py-2 text-start">{{ __('Type') }}</th>
                        <th class="px-4 py-2 text-start">{{ __('Reference') }}</th>
                        <th class="px-4 py-2 text-start">{{ __('Date') }}</th>
                        <th class="hidden px-4 py-2 text-start sm:table-cell">{{ __('Time') }}</th>
                        <th class="hidden px-4 py-2 text-start md:table-cell">{{ __('Given by') }}</th>
                        <th class="hidden px-4 py-2 text-start lg:table-cell">{{ __('Car used') }}</th>
                        <th class="px-4 py-2 text-start">{{ __('Status') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-chrome-50">
                    @forelse ($jobs as $job)
                        <tr class="hover:bg-chrome-50">
                            <td class="px-4 py-2">
                                <span class="rounded px-2 py-0.5 text-[11px] font-semibold uppercase {{ $job['kind'] === 'rental' ? 'bg-sky-100 text-sky-700' : 'bg-violet-100 text-violet-700' }}">
                                    {{ $job['type'] }}
                                </span>
                            </td>
                            <td class="px-4 py-2 font-medium">
                                <a href="{{ url($job['url']) }}" wire:navigate class="text-primary-700 hover:underline">
                                    {{ $job['reference'] ?: '—' }}
                                </a>
                            </td>
                            <td class="px-4 py-2 text-chrome-600">{{ $job['at']?->isoFormat('DD-MMM-YYYY') ?? '—' }}</td>
                            <td class="hidden px-4 py-2 text-chrome-600 sm:table-cell">{{ $job['at']?->isoFormat('HH:mm') ?? '—' }}</td>
                            <td class="hidden px-4 py-2 text-chrome-600 md:table-cell">{{ $job['given_by'] ?: '—' }}</td>
                            <td class="hidden px-4 py-2 text-chrome-600 lg:table-cell">{{ $job['car'] ?: '—' }}</td>
                            <td class="px-4 py-2 text-chrome-500">{{ $job['status'] !== '' ? __(ucfirst($job['status'])) : '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-10 text-center text-sm text-chrome-400">
                                {{ __('Nothing yet — trips and rentals given to this driver will appear here.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endif
