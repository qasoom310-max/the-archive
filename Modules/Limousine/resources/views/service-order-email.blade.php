{{-- Signing invitation. Short on purpose: the customer is being asked to do one
     thing, so the trip details are a quick confirmation and the button is the
     point. The plain URL is repeated underneath for mail clients that strip
     buttons. --}}
<x-mail::message>
# {{ __('Service Order') }} {{ $leg->reference }}

{{ __('Thank you for travelling with us. Please confirm the trip below by signing your service order.') }}

@if ($details !== [])
<x-mail::table>
| | |
|:--|:--|
@foreach ($details as $label => $value)
| **{{ $label }}** | {{ $value }} |
@endforeach
</x-mail::table>
@endif

<x-mail::button :url="$signUrl">
{{ __('Review & sign') }}
</x-mail::button>

{{ __('By signing you confirm the driver arrived and the service was provided.') }}

{{ __('If the button does not work, copy this link into your browser:') }}
{{ $signUrl }}

{{ __('Thanks') }},<br>
{{ $companyName }}
</x-mail::message>
