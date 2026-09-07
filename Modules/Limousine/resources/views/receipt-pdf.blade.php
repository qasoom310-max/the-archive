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
    <style>
        @page { margin: 30px 34px 60px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10.5px; color: #111; }
        .logo { height: {{ (int) round(52 * $logoScale / 100) }}px; }
        .brand-fallback { background: #f5ef1a; display: inline-block; padding: 8px 18px; font-size: 22px; font-weight: bold; letter-spacing: 1px; }
        h1 { font-size: 17px; margin: 14px 0 2px; text-decoration: underline; }
        .muted { color: #666; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 6px 4px; vertical-align: top; }
        .lbl { font-weight: bold; width: 130px; }
        .amount-box { border: 2px solid #111; padding: 12px 16px; text-align: center; margin: 16px 0; }
        .amount { font-size: 26px; font-weight: bold; }
        .owed { color: #b00020; font-weight: bold; }
        .settled { color: #0a7d33; font-weight: bold; }
        .detail td { border-bottom: 1px solid #eee; }
        .sign { margin-top: 34px; }
        .sign td { padding-top: 26px; border-top: 1px solid #999; font-size: 9.5px; color: #555; }
        .foot { margin-top: 26px; font-size: 9.5px; color: #333; line-height: 1.6; }
    </style>
</head>
<body>

@if ($logoPath)
    <img src="{{ $logoPath }}" class="logo" alt="{{ $companyName }}">
@else
    <div class="brand-fallback">{{ $companyName }}</div>
@endif

<h1>{{ __('Receipt') }}</h1>

<table>
    <tr>
        <td style="width:60%"><b>{{ __('Receipt no.') }}:</b> {{ $reference }}</td>
        <td style="text-align:right">{{ __('Date') }}: <b>{{ $date }}</b></td>
    </tr>
</table>

<div class="amount-box">
    <div class="muted" style="font-size:9.5px; letter-spacing:1px">{{ __('AMOUNT RECEIVED') }}</div>
    <div class="amount">{{ \App\Erp\Views\ValueFormat::money($amount) }}</div>

    {{-- Stated, not implied. A part payment that says only what was taken
         leaves the customer to work out what they still owe. --}}
    @if ($balance !== null)
        <div style="margin-top:10px" class="{{ $balance > 0 ? 'owed' : 'settled' }}">
            @if ($balance > 0)
                {{ __('Balance still to pay') }}: {{ \App\Erp\Views\ValueFormat::money($balance) }}
            @else
                {{ __('Paid in full — nothing further owed.') }}
            @endif
        </div>
    @endif
</div>

<table class="detail">
    <tr>
        <td class="lbl">{{ __('Received from') }}</td>
        <td>{{ $customerName !== '' ? $customerName : '—' }}@if ($customerPhone !== '') · {{ $customerPhone }}@endif</td>
    </tr>
    {{-- What the money was for: without this the customer cannot match the
         payment to a journey. --}}
    @if ($bookingReference !== '')
        <tr>
            <td class="lbl">{{ __('For booking') }}</td>
            <td>{{ $bookingReference }}</td>
        </tr>
    @endif
    @if ($invoiceReference !== '')
        <tr>
            <td class="lbl">{{ __('Against invoice') }}</td>
            <td>{{ $invoiceReference }}</td>
        </tr>
    @endif
    <tr>
        <td class="lbl">{{ __('Paid by') }}</td>
        <td>{{ $method }}</td>
    </tr>
    @if ($notes !== '')
        <tr>
            <td class="lbl">{{ __('Note') }}</td>
            <td>{{ $notes }}</td>
        </tr>
    @endif
</table>

{{-- The signature strip of the pad this replaced: a slot to sign, a slot to
     stamp, and the name of whoever raised it. `Prepared by` is only a third
     column when we know the name — older receipts keep the two even slots
     rather than printing an empty label. --}}
<table class="sign">
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
