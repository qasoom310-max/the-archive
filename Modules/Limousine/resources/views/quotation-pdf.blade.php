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
    <style>
        @page { margin: 30px 34px 60px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10.5px; color: #111; }
        .logo { height: {{ (int) round(52 * $logoScale / 100) }}px; }
        .brand-fallback { background: #f5ef1a; display: inline-block; padding: 8px 18px; font-size: 22px; font-weight: bold; letter-spacing: 1px; }
        h1 { font-size: 17px; margin: 14px 0 2px; text-decoration: underline; }
        .muted { color: #666; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 6px 4px; vertical-align: top; }
        .lbl { font-weight: bold; width: 120px; }
        .conf { text-align: right; font-size: 12px; }
        .conf b { font-size: 17px; }
        .trips { margin-top: 16px; }
        .trips th { text-align: left; font-size: 9.5px; text-transform: uppercase; letter-spacing: .4px; color: #555; border-bottom: 1px solid #999; padding: 5px 4px; }
        .trips td { border-bottom: 1px solid #eee; padding: 7px 4px; }
        .num { text-align: right; }
        .total-row td { border-bottom: none; border-top: 2px solid #111; font-weight: bold; font-size: 12px; padding-top: 8px; }
        .notes { margin-top: 18px; }
        .foot { margin-top: 26px; font-size: 9.5px; color: #333; line-height: 1.6; }
    </style>
</head>
<body>

@if ($logoPath)
    <img src="{{ $logoPath }}" class="logo" alt="{{ $companyName }}">
@else
    <div class="brand-fallback">{{ $companyName }}</div>
@endif

<h1>{{ __('Quotation') }}</h1>

<table>
    <tr>
        <td style="width:60%"><b>{{ __('Date') }} :</b> {{ $quoteDate }}</td>
        <td class="conf">{{ __('Quotation No.') }}: <b>{{ $reference }}</b>
            @if ($validUntil)
                <div class="muted" style="font-size:9px">{{ __('Valid until') }} {{ $validUntil }}</div>
            @endif
        </td>
    </tr>
</table>

<table style="margin-top:8px">
    <tr>
        <td class="lbl">{{ __('Customer') }}</td>
        <td>{{ $customerName ?: '—' }}@if ($customerPhone) · {{ $customerPhone }} @endif</td>
    </tr>
    @if ($contactPerson)
        <tr><td class="lbl">{{ __('Contact person') }}</td><td>{{ $contactPerson }}</td></tr>
    @endif
    @if ($requestedBy)
        <tr><td class="lbl">{{ __('Requested by') }}</td><td>{{ $requestedBy }}</td></tr>
    @endif
    @if ($preparedBy)
        <tr><td class="lbl">{{ __('Prepared by') }}</td><td>{{ $preparedBy }}</td></tr>
    @endif
</table>

<table class="trips">
    <thead>
        <tr>
            <th>#</th>
            <th>{{ __('Trip') }}</th>
            <th>{{ __('Date & time') }}</th>
            <th class="num">{{ __('Amount') }}</th>
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
        <tr class="total-row">
            <td colspan="3">{{ __('Total') }}</td>
            <td class="num">{{ \App\Erp\Views\ValueFormat::money($total) }}</td>
        </tr>
    </tbody>
</table>

@if ($notes)
    <div class="notes">
        <b>{{ __('Comments') }}</b>
        <div class="muted" style="margin-top:3px; white-space:pre-line">{{ $notes }}</div>
    </div>
@endif

<div class="foot">
    {{ __('Prices are in Bahraini Dinar. This quotation is valid until the date shown above.') }}
</div>

<x-document-footer />

</body>
</html>
