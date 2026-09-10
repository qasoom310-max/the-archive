{{-- Notice to a corporate customer: the job is done. No signing button — the
     company was not in the car, so it is being informed, not asked to attest. --}}
<x-mail::message>
# {{ __('Service Order') }} {{ $leg->reference }}

{{ __('This is to confirm that our driver has reached your customer and the service below has been completed.') }}

@if ($details !== [])
<x-mail::table>
| | |
|:--|:--|
@foreach ($details as $label => $value)
| **{{ $label }}** | {{ $value }} |
@endforeach
</x-mail::table>
@endif

{{ __('No action is needed. Please keep this as your record of the trip.') }}

{{ __('Thanks') }},<br>
{{ $companyName }}
</x-mail::message>
