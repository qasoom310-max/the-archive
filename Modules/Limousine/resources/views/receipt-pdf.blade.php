{{-- The customer's copy of a payment. Table-based layout with inline styles
     because DomPDF supports neither flexbox nor grid.

     The AMOUNT RECEIVED is the headline — that is what the customer handed
     over and what they are keeping this for. What is still owed sits under it,
     stated plainly rather than left to be worked out. --}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ __('Receipt') }} {{ $reference }}</title>
    <x-pdf-styles />
    <style>
        @page { margin: 30px 34px 60px; }
    </style>
</head>
<body>

<table class="doc-head">
    <tr>
        <td style="width:55%">
            @if ($logoPath)
                <img src="{{ $logoPath }}" style="height:{{ (int) round(46 * $logoScale / 100) }}px" alt="{{ $companyName }}">
            @else
                <div class="doc-brand-fallback">{{ $companyName }}</div>
            @endif
        </td>
        <td class="doc-title-block">
            <div class="doc-title">{{ __('Receipt') }}</div>
            <div class="doc-ref">{{ __('Receipt no.') }}: <b>{{ $reference }}</b></div>
            <div class="doc-sub">{{ __('Date') }}: {{ $date }}</div>
        </td>
    </tr>
</table>
<div class="doc-accent">&nbsp;</div>

<div class="doc-figure-box">
    <div class="doc-figure-label">{{ __('AMOUNT RECEIVED') }}</div>
    <div class="doc-figure">{{ \App\Erp\Views\ValueFormat::money($amount) }}</div>

    {{-- Stated, not implied. A part payment that says only what was taken
         leaves the customer to work out what they still owe. --}}
    @if ($balance !== null)
        <div style="margin-top:8px" class="{{ $balance > 0 ? 'owed' : 'settled' }}">
            @if ($balance > 0)
                {{ __('Balance still to pay') }}: {{ \App\Erp\Views\ValueFormat::money($balance) }}
            @else
                {{ __('Paid in full — nothing further owed.') }}
            @endif
        </div>
    @endif
</div>

<table class="doc-meta">
    <tr>
        <td class="k">{{ __('Received from') }}</td>
        <td class="v">{{ $customerName !== '' ? $customerName : '—' }}@if ($customerPhone !== '') · {{ $customerPhone }}@endif</td>
    </tr>
    {{-- What the money was for: without this the customer cannot match the
         payment to a journey. --}}
    @if ($bookingReference !== '')
        <tr>
            <td class="k">{{ __('For booking') }}</td>
            <td class="v">{{ $bookingReference }}</td>
        </tr>
    @endif
    @if ($invoiceReference !== '')
        <tr>
            <td class="k">{{ __('Against invoice') }}</td>
            <td class="v">{{ $invoiceReference }}</td>
        </tr>
    @endif
    <tr>
        <td class="k">{{ __('Paid by') }}</td>
        <td class="v">{{ $method }}</td>
    </tr>
    @if ($notes !== '')
        <tr>
            <td class="k">{{ __('Note') }}</td>
            <td class="v">{{ $notes }}</td>
        </tr>
    @endif
</table>

{{-- The signature strip of the pad this replaced: a slot to sign, a slot to
     stamp, and the name of whoever raised it. `Prepared by` is only a third
     column when we know the name — older receipts keep the two even slots
     rather than printing an empty label. --}}
<table class="doc-sign">
    <tr>
        <td style="width:{{ $preparedBy !== '' ? '34%' : '50%' }}">{{ __('Received by') }}</td>
        {{-- A receipt is our acknowledgement that the money arrived, so the
             second slot is the company stamp, not the customer's signature —
             the customer is not attesting to anything by being paid up. --}}
        <td style="width:{{ $preparedBy !== '' ? '33%' : '50%' }}">{{ __('Stamp') }}</td>
        @if ($preparedBy !== '')
            <td style="width:33%">{{ __('Prepared by') }}: <b>{{ $preparedBy }}</b></td>
        @endif
    </tr>
</table>

<x-document-footer />

</body>
</html>
