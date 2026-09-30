{{-- The quotation going out. Short: the customer is being asked to say yes or
     no, and the detail is in the attachment they will forward or print. --}}
<x-mail::message>
# {{ __('Quotation') }} {{ $quote->reference }}

{{ __('Thank you for your enquiry. Our quotation is attached, and summarised below.') }}

<x-mail::panel>
@if ($withoutTotal ?? false)
**{{ __('The rates for each journey are in the attached quotation.') }}**
@else
**{{ __('Total') }}: {{ \App\Erp\Views\ValueFormat::money($total) }}**
@endif
@if ($quote->valid_until)

{{ __('Valid until') }} {{ $quote->valid_until->isoFormat('DD-MMM-YYYY') }}
@endif
</x-mail::panel>

{{ __('Reply to this email to confirm, or to ask for anything to be changed.') }}

{{ __('Thanks') }},<br>
{{ $companyName }}
</x-mail::message>
