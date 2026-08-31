{{-- The coupon, sent to the customer. No button and no link: there is nothing
     to do here and nothing to expire — the code IS the thing, so it is in the
     body where it can be read without opening the attachment, and the voucher
     rides along as a PDF to keep. --}}
<x-mail::message>
# {{ __('Your credit coupon') }}

{{ __('We are sorry your trip could not go ahead. The amount you paid is held for you as credit, ready to use on a future booking.') }}

<x-mail::panel>
### {{ $coupon->code }}
**{{ __('Value remaining') }}: {{ \App\Erp\Views\ValueFormat::money($remaining) }}**
@if ($coupon->expires_at)

{{ __('Valid until') }} {{ $coupon->expires_at->isoFormat('DD-MMM-YYYY') }}
@endif
</x-mail::panel>

{{ __('Quote this code when you book and the value comes off your fare. You can use it across more than one trip until it runs out.') }}

{{ __('Your coupon is attached for your records.') }}

{{ __('Thanks') }},<br>
{{ $companyName }}
</x-mail::message>
