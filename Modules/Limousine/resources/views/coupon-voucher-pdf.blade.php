{{-- The customer's copy of a refund coupon. Table-based layout with inline
     styles because DomPDF supports neither flexbox nor grid.

     The REMAINING balance is the headline, not the face value: a coupon is
     spent in pieces, so what the customer needs to see is what they can still
     put towards a trip. Where the rest of it went is listed underneath, so the
     figure is never just asserted. --}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ __('Refund coupon') }} {{ $code }}</title>
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
        .code-box { border: 2px solid #111; padding: 12px 16px; text-align: center; margin: 16px 0; }
        .code { font-size: 26px; font-weight: bold; letter-spacing: 3px; }
        .balance { font-size: 22px; font-weight: bold; }
        .void { color: #b00020; font-weight: bold; }
        .money td { border-bottom: 1px solid #ddd; }
        .money .total td { border-bottom: none; font-weight: bold; }
        .spent { margin-top: 18px; }
        .spent th { text-align: left; font-size: 9.5px; color: #555; border-bottom: 1px solid #bbb; padding: 4px; }
        .spent td { font-size: 10px; padding: 4px; border-bottom: 1px solid #eee; }
        .foot { margin-top: 26px; font-size: 9.5px; color: #333; line-height: 1.6; }
    </style>
</head>
<body>

@if ($logoPath)
    <img src="{{ $logoPath }}" class="logo" alt="{{ $companyName }}">
@else
    <div class="brand-fallback">{{ $companyName }}</div>
@endif

<h1>{{ __('Refund coupon') }}</h1>

<table>
    <tr>
        <td style="width:60%"><b>{{ __('Issued') }} :</b> {{ $issuedOn }}</td>
        <td style="text-align:right">{{ __('Valid until') }}: <b>{{ $expiresOn }}</b></td>
    </tr>
</table>

<div class="code-box">
    <div class="muted" style="font-size:9.5px; letter-spacing:1px">{{ __('COUPON CODE') }}</div>
    <div class="code">{{ $code }}</div>
    <div style="margin-top:10px" class="{{ $expired || $remaining <= 0 ? 'void' : '' }}">
        @if ($expired)
            {{ __('EXPIRED — this coupon can no longer be used.') }}
        @elseif ($remaining <= 0)
            {{ __('FULLY USED — nothing remains on this coupon.') }}
        @else
            <span class="muted" style="font-size:9.5px">{{ __('Value remaining') }}</span><br>
            <span class="balance">{{ \App\Erp\Views\ValueFormat::money($remaining) }}</span>
        @endif
    </div>
</div>

<table>
    <tr>
        <td class="lbl">{{ __('Customer') }}</td>
        <td>{{ $customerName ?: '—' }}@if ($customerPhone) · {{ $customerPhone }} @endif</td>
    </tr>
    @if ($fromTrip)
        <tr>
            <td class="lbl">{{ __('For cancelled trip') }}</td>
            <td>{{ $fromTrip }}</td>
        </tr>
    @endif
</table>

<table class="money" style="margin-top:12px">
    <tr>
        <td>{{ __('Issued') }}</td>
        <td style="text-align:right">{{ \App\Erp\Views\ValueFormat::money($issued) }}</td>
    </tr>
    <tr>
        <td>{{ __('Used') }}</td>
        <td style="text-align:right">{{ \App\Erp\Views\ValueFormat::money($used) }}</td>
    </tr>
    <tr class="total">
        <td>{{ __('Remaining') }}</td>
        <td style="text-align:right">{{ \App\Erp\Views\ValueFormat::money($remaining) }}</td>
    </tr>
</table>

@if (count($redemptions))
    <div class="spent">
        <table>
            <thead>
                <tr>
                    <th>{{ __('Used on') }}</th>
                    <th>{{ __('Booking') }}</th>
                    <th style="text-align:right">{{ __('Amount') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($redemptions as $r)
                    <tr>
                        <td>{{ $r->created_at?->isoFormat('DD-MMM-YYYY') }}</td>
                        <td>{{ $r->booking_reference ?: '—' }}</td>
                        <td style="text-align:right">{{ \App\Erp\Views\ValueFormat::money((float) $r->amount) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

<div class="foot">
    {{ __('Quote this code when booking and the value is taken off your fare. It may be used across more than one trip until it runs out, and expires on the date above.') }}
</div>

<x-document-footer />

</body>
</html>
