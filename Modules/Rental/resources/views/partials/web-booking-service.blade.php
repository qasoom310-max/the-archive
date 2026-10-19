{{-- Rental or limousine, as a badge. Expects $booking (RentalWebBooking). --}}
@if ($booking->isLimousine())
    <span class="rounded bg-indigo-100 px-2 py-0.5 text-[11px] font-semibold uppercase text-indigo-700" data-service="limousine">{{ __('Limousine') }}</span>
@else
    <span class="rounded bg-sky-100 px-2 py-0.5 text-[11px] font-semibold uppercase text-sky-700" data-service="rental">{{ __('Car rental') }}</span>
@endif
