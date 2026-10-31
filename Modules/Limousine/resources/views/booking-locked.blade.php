{{-- A booking whose details are held back from this user until every trip on
     it has a car and a driver (Modules\Limousine\Support\TripDetailsLock). Only
     what is needed to choose the crew is here; nothing else was loaded. --}}
<div class="mx-auto max-w-3xl p-4 sm:p-6" data-booking-locked>
    <x-form-breadcrumb :parent="__('Bookings')" :parent-url="url('/app/limousine/booking')" :current="$reference ?: __('Booking')" />

    <div class="mb-5 rounded-2xl bg-amber-50 p-4 text-sm text-amber-800 ring-1 ring-amber-200">
        <p class="font-semibold">🔒 {{ __('The details of this booking show once every trip on it has a car and a driver.') }}</p>
        <p class="mt-1">{{ __('Assign them on the Bookings list — the customer is sent their driver automatically as soon as you do.') }}</p>
    </div>

    <div class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-chrome-900/[0.06]">
        <table class="w-full text-sm">
            <thead class="bg-chrome-50 text-xs uppercase tracking-wide text-chrome-500">
                <tr>
                    <th class="px-4 py-2 text-start font-medium">{{ __('Trip') }}</th>
                    <th class="px-4 py-2 text-start font-medium">{{ __('Pick-up') }}</th>
                    <th class="px-4 py-2 text-start font-medium">{{ __('Car type') }}</th>
                    <th class="px-4 py-2 text-start font-medium">{{ __('Waiting for') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-chrome-100">
                @foreach ($trips as $trip)
                    <tr>
                        <td class="px-4 py-2 font-medium text-chrome-900">{{ $trip['reference'] ?: '—' }}</td>
                        <td class="px-4 py-2 text-chrome-700">{{ $trip['when'] ?: '—' }}</td>
                        <td class="px-4 py-2 text-chrome-700">{{ $trip['car_type'] ?: '—' }}</td>
                        <td class="px-4 py-2">
                            @if ($trip['missing'] !== '')
                                <span class="rounded bg-red-100 px-1.5 py-0.5 text-[11px] font-semibold text-red-700">{{ $trip['missing'] }}</span>
                            @else
                                <span class="text-xs text-emerald-700">✓ {{ __('Assigned') }}</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-5 flex justify-end">
        <a href="{{ $bookingsUrl }}" wire:navigate class="o-btn-primary text-sm" data-assign-on-list>{{ __('Assign car & driver') }}</a>
    </div>
</div>
