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
    <x-pdf-styles />
    <style>
        @page { margin: 30px 34px 60px; }
        .code { font-size: 24px; font-weight: bold; letter-spacing: 3px; color: #eab308; }
    </style>
</head>
<body>

<table class="doc-head">
    <tr>
        <td style="width:55%">
            @if ($logoPath)
                <div class="doc-logo-chip"><img src="{{ $logoPath }}" style="height:{{ (int) round(40 * $logoScale / 100) }}px" alt="{{ $companyName }}"></div>
            @else
                <div class="doc-brand-fallback">{{ $companyName }}</div>
            @endif
        </td>
        <td class="doc-title-block">
            <div class="doc-title">{{ __('Refund coupon') }}</div>
            <div class="doc-ref">{{ __('Issued') }}: <b>{{ $issuedOn }}</b></div>
            <div class="doc-sub">{{ __('Valid until') }}: {{ $expiresOn }}</div>
        </td>
    </tr>
</table>
<div class="doc-accent">&nbsp;</div>

<div class="doc-figure-box">
    <div class="doc-figure-label">{{ __('COUPON CODE') }}</div>
    <div class="code">{{ $code }}</div>
    <div style="margin-top:8px" class="{{ $expired || $remaining <= 0 ? 'owed' : '' }}">
        @if ($expired)
            {{ __('EXPIRED — this coupon can no longer be used.') }}
        @elseif ($remaining <= 0)
            {{ __('FULLY USED — nothing remains on this coupon.') }}
        @else
            <span class="doc-figure-label">{{ __('Value remaining') }}</span><br>
            <span class="doc-figure">{{ \App\Erp\Views\ValueFormat::money($remaining) }}</span>
        @endif
    </div>
</div>

<table class="doc-meta">
    <tr>
        <td class="k">{{ __('Customer') }}</td>
        <td class="v">{{ $customerName ?: '—' }}@if ($customerPhone) · {{ $customerPhone }} @endif</td>
    </tr>
    @if ($fromTrip)
        <tr>
            <td class="k">{{ __('For cancelled trip') }}</td>
            <td class="v">{{ $fromTrip }}</td>
        </tr>
    @endif
</table>

<table style="width:100%; margin-top:10px">
    <tr>
        <td></td>
        <td style="width:230px">
            <table class="doc-totals">
                <tr>
                    <td class="k">{{ __('Issued') }}</td>
                    <td class="v">{{ \App\Erp\Views\ValueFormat::money($issued) }}</td>
                </tr>
                <tr>
                    <td class="k">{{ __('Used') }}</td>
                    <td class="v">{{ \App\Erp\Views\ValueFormat::money($used) }}</td>
                </tr>
                <tr class="grand">
                    <td class="k">{{ __('Remaining') }}</td>
                    <td class="v">{{ \App\Erp\Views\ValueFormat::money($remaining) }}</td>
                </tr>
            </table>
        </td>
    </tr>
</table>

@if (count($redemptions))
    <div style="margin-top:16px">
        <table class="doc-table">
            <thead>
                <tr>
                    <th>{{ __('Used on') }}</th>
                    <th>{{ __('Booking') }}</th>
                    <th class="num">{{ __('Amount') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($redemptions as $r)
                    <tr>
                        <td>{{ $r->created_at?->isoFormat('DD-MMM-YYYY') }}</td>
                        <td>{{ $r->booking_reference ?: '—' }}</td>
                        <td class="num">{{ \App\Erp\Views\ValueFormat::money((float) $r->amount) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

<div class="doc-note">
    {{ __('Quote this code when booking and the value is taken off your fare. It may be used across more than one trip until it runs out, and expires on the date above.') }}
</div>

<x-document-footer />

</body>
</html>
