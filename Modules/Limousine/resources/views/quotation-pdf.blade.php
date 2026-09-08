{{-- The quotation as the customer receives it. Table-based layout with inline
     styles because DomPDF supports neither flexbox nor grid.

     Trip by trip, then the total — a quote for three journeys that showed only
     a single figure would be asking the customer to accept a number with
     nothing behind it. --}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ __('Quotation') }} {{ $reference }}</title>
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
            <div class="doc-title">{{ __('Quotation') }}</div>
            <div class="doc-ref">{{ __('Quotation No.') }}: <b>{{ $reference }}</b></div>
            <div class="doc-sub">{{ __('Date') }}: {{ $quoteDate }}@if ($validUntil) · {{ __('Valid until') }} {{ $validUntil }}@endif</div>
        </td>
    </tr>
</table>
<div class="doc-accent">&nbsp;</div>

<table class="doc-meta">
    <tr>
        <td class="k">{{ __('Customer') }}</td>
        <td class="v">{{ $customerName ?: '—' }}@if ($customerPhone) · {{ $customerPhone }} @endif</td>
    </tr>
    @if ($contactPerson)
        <tr><td class="k">{{ __('Contact person') }}</td><td class="v">{{ $contactPerson }}</td></tr>
    @endif
    @if ($requestedBy)
        <tr><td class="k">{{ __('Requested by') }}</td><td class="v">{{ $requestedBy }}</td></tr>
    @endif
    @if ($preparedBy)
        <tr><td class="k">{{ __('Prepared by') }}</td><td class="v">{{ $preparedBy }}</td></tr>
    @endif
</table>

<table class="doc-table" style="margin-top:16px">
    <thead>
        <tr>
            <th style="width:26px">#</th>
            <th>{{ __('Trip') }}</th>
            <th style="width:110px">{{ __('Date & time') }}</th>
            <th class="num" style="width:90px">{{ __('Amount') }}</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($legs as $i => $leg)
            <tr>
                <td>{{ $i + 1 }}</td>
                <td>
                    {{ $leg->from_location ?: '—' }}
                    @if ($leg->service_type === 'chauffeur')
                        <div class="muted" style="font-size:9.5px">
                            {{ __('Chauffeur') }}@if ($leg->hours) · {{ rtrim(rtrim(number_format((float) $leg->hours, 1), '0'), '.') }} {{ __('hours per day') }}@endif
                            @if ($leg->days > 1) · {{ $leg->days }} {{ __('day(s)') }} @endif
                        </div>
                    @else
                        → {{ $leg->to_location ?: '—' }}
                    @endif
                </td>
                <td>{{ $leg->start_at?->isoFormat('DD-MMM-YY HH:mm') ?? '—' }}</td>
                <td class="num">{{ \App\Erp\Views\ValueFormat::money((float) $leg->net_amount) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<table style="width:100%; margin-top:6px">
    <tr>
        <td></td>
        <td style="width:230px">
            <table class="doc-totals">
                <tr class="grand">
                    <td class="k">{{ __('Total') }}</td>
                    <td class="v">{{ \App\Erp\Views\ValueFormat::money($total) }}</td>
                </tr>
            </table>
        </td>
    </tr>
</table>

@if ($notes)
    <div style="margin-top:18px">
        <b>{{ __('Comments') }}</b>
        <div class="muted" style="margin-top:3px; white-space:pre-line">{{ $notes }}</div>
    </div>
@endif

<div class="doc-note">
    {{ __('Prices are in Bahraini Dinar. This quotation is valid until the date shown above.') }}
</div>

<x-document-footer />

</body>
</html>
