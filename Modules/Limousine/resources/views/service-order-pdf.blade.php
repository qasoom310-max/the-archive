{{-- Service Order — one trip, one page. Table-based layout with inline styles
     because DomPDF supports neither flexbox nor grid. Field rows use a
     bottom-ruled cell so the printed sheet keeps the fill-in-by-hand lines
     (time out/in, driver signature) the office still writes on. --}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ __('Service Order') }} {{ $confirmationNo }}</title>
    <style>
        @page { margin: 26px 30px 60px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10.5px; color: #111; }
        .logo { height: {{ (int) round(52 * $logoScale / 100) }}px; }
        .brand-fallback { background: #f5ef1a; display: inline-block; padding: 8px 18px; font-size: 22px; font-weight: bold; letter-spacing: 1px; }
        h1 { font-size: 17px; margin: 14px 0 2px; text-decoration: underline; }
        .muted { color: #666; }
        .section { font-size: 11px; font-weight: bold; margin: 14px 0 2px; }
        .rule { border-bottom: 1px solid #bbb; width: 150px; margin-bottom: 6px; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 7px 4px; vertical-align: bottom; }
        .lbl { font-weight: bold; width: 118px; }
        .val { border-bottom: 1px solid #ccc; }
        .conf { text-align: right; font-size: 12px; }
        .conf b { font-size: 19px; }
        .foot { margin-top: 26px; font-size: 9.5px; color: #333; line-height: 1.5; }
        .sig-img { max-height: 54px; }
        .signed-note { color: #0a7d3f; font-size: 9px; }
    </style>
</head>
<body>

@if ($logoPath)
    <img src="{{ $logoPath }}" class="logo" alt="{{ $companyName }}">
@else
    <div class="brand-fallback">{{ $companyName }}</div>
@endif

<h1>{{ __('Service Order') }}</h1>

<table>
    <tr>
        <td style="width:60%"><b>{{ __('Date') }} :</b> {{ $issuedOn }}</td>
        <td class="conf">{{ __('Confirmation No.') }}: <b>{{ $confirmationNo }}</b>
            @if ($bookingReference)
                <div class="muted" style="font-size:9px">{{ $bookingReference }}</div>
            @endif
        </td>
    </tr>
</table>

<div class="section">{{ __('CUSTOMER DETAILS') }}</div>
<div class="rule"></div>
<table>
    <tr>
        <td class="lbl">{{ __('Customer Name') }} :</td>
        <td class="val" style="width:36%">{{ $customerName }}</td>
        <td class="lbl" style="width:80px">{{ __('Telephone') }} :</td>
        <td class="val">{{ $customerPhone }}</td>
    </tr>
</table>

<div class="section">{{ __('SERVICE DETAILS') }}</div>
<div class="rule"></div>
<table>
    <tr>
        <td class="lbl">{{ __('Service Date') }}:</td>
        <td class="val" style="width:36%">{{ $serviceDate }}</td>
        <td class="lbl" style="width:80px">{{ __('Service Time') }} :</td>
        <td class="val">{{ $serviceTime }}</td>
    </tr>
    <tr>
        <td class="lbl">{{ __('Vehicle') }} :</td>
        <td class="val">{{ $vehicle }}</td>
        <td class="lbl">{{ __('Flight number') }} :</td>
        <td class="val">{{ $flightNumber }}</td>
    </tr>
    <tr>
        <td class="lbl">{{ __('PAX Name') }} :</td>
        <td class="val">{{ $paxName }}</td>
        <td class="lbl">{{ __('PAX Contact') }} :</td>
        <td class="val">{{ $paxContact }}</td>
    </tr>
    <tr>
        <td class="lbl">{{ __('Pick up') }} :</td>
        <td class="val" colspan="3">{{ $pickup }}</td>
    </tr>
    <tr>
        <td class="lbl">{{ __('Drop off') }} :</td>
        <td class="val" colspan="3">{{ $dropoff }}</td>
    </tr>
    {{-- Left blank on purpose: the driver writes these on the sheet. --}}
    <tr>
        <td class="lbl">{{ __('Customer Time Out') }}:</td>
        <td class="val">&nbsp;</td>
        <td class="lbl">{{ __('Customer Time In') }}:</td>
        <td class="val">&nbsp;</td>
    </tr>
    <tr>
        <td class="lbl">{{ __("Driver's Name") }} :</td>
        <td class="val">{{ $driverName }}</td>
        <td class="lbl">{{ __("Driver's signature") }}:</td>
        <td class="val">&nbsp;</td>
    </tr>
    <tr>
        <td class="lbl">{{ __('Amount') }} :</td>
        <td class="val">{{ number_format($amount, 3) }} {{ __('BHD') }}</td>
        <td colspan="2"></td>
    </tr>
    <tr>
        <td class="lbl">{{ __('Payment Method') }}:</td>
        <td colspan="3">
            &#9744;&nbsp;&nbsp;{{ __('Cash') }}
            &nbsp;&nbsp;&nbsp;&nbsp;&#9744;&nbsp;&nbsp;{{ __('A/C') }}
            &nbsp;&nbsp;&nbsp;&nbsp;&#9744;&nbsp;&nbsp;{{ __('Other') }}
        </td>
    </tr>
    <tr>
        <td class="lbl">{{ __('Remark') }} :</td>
        <td class="val" colspan="3">{{ $remark }}</td>
    </tr>
    <tr>
        <td class="lbl">{{ __('Cash Received By') }}:</td>
        <td class="val">&nbsp;</td>
        <td class="lbl">{{ __('Signature') }} :</td>
        <td class="val">&nbsp;</td>
    </tr>
</table>

{{-- Customer signature. Signed online → the drawn image plus when and by whom,
     which is what makes this the proof the trip was delivered. Unsigned → a
     ruled line so the sheet still works on paper. --}}
<table style="margin-top:38px">
    <tr>
        <td style="width:55%"></td>
        <td class="lbl" style="width:110px">{{ __('Customer Signature') }} :</td>
        <td class="val" style="height:56px">
            @if ($signatureData)
                <img src="{{ $signatureData }}" class="sig-img" alt="{{ __('Customer Signature') }}">
            @else
                &nbsp;
            @endif
        </td>
    </tr>
    @if ($signatureData)
        <tr>
            <td colspan="2"></td>
            <td class="signed-note">
                {{ __('Signed online') }}@if ($signedName) — {{ $signedName }}@endif
                @if ($signedAt) · {{ $signedAt }} @endif
            </td>
        </tr>
    @endif
</table>

<x-document-footer />

</body>
</html>
