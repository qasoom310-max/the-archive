{{-- Statement of account. Table layout with inline styles because DomPDF
     supports neither flexbox nor grid.

     Every charge and every payment in date order with a running balance, so
     the customer's accounts department can tick it against their own ledger
     line by line — which is the whole reason they asked for it. --}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ __('Statement of account') }} — {{ $customer->name }}</title>
    <style>
        @page { margin: 28px 30px 60px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #111; }
        .logo { height: {{ (int) round(52 * $logoScale / 100) }}px; }
        .brand-fallback { background: #f5ef1a; display: inline-block; padding: 8px 18px; font-size: 22px; font-weight: bold; letter-spacing: 1px; }
        h1 { font-size: 17px; margin: 14px 0 2px; text-decoration: underline; }
        .muted { color: #666; }
        table { width: 100%; border-collapse: collapse; }
        td, th { padding: 5px 4px; vertical-align: top; }
        .head td { padding: 2px 4px; }
        .lbl { font-weight: bold; width: 110px; }
        .ledger th { border-bottom: 2px solid #111; text-align: left; font-size: 9px; text-transform: uppercase; letter-spacing: .4px; }
        .ledger td { border-bottom: 1px solid #eee; }
        .num { text-align: right; white-space: nowrap; }
        .open td { background: #f4f4f4; font-weight: bold; }
        .close td { border-top: 2px solid #111; font-weight: bold; font-size: 11.5px; }
        .owed { color: #b00020; }
        .settled { color: #0a7d33; }
        .foot { margin-top: 22px; font-size: 9px; color: #333; line-height: 1.6; }
    </style>
</head>
<body>

@if ($logoPath)
    <img src="{{ $logoPath }}" class="logo" alt="{{ $companyName }}">
@else
    <div class="brand-fallback">{{ $companyName }}</div>
@endif

<h1>{{ __('Statement of account') }}</h1>

<table class="head">
    <tr>
        <td class="lbl">{{ __('Account') }}</td>
        <td>{{ $customer->name }}@if ($customer->phone) · {{ $customer->phone }}@endif</td>
    </tr>
    <tr>
        <td class="lbl">{{ __('Period') }}</td>
        <td>
            @if ($from || $to)
                {{ $from?->isoFormat('DD-MMM-YYYY') ?? '…' }} — {{ $to?->isoFormat('DD-MMM-YYYY') ?? '…' }}
            @else
                {{ __('All time') }}
            @endif
        </td>
    </tr>
    <tr>
        <td class="lbl">{{ __('Issued') }}</td>
        <td>{{ now()->isoFormat('DD-MMM-YYYY') }}</td>
    </tr>
</table>

<table class="ledger" style="margin-top:14px">
    <tr>
        <th style="width:74px">{{ __('Date') }}</th>
        <th style="width:82px">{{ __('Reference') }}</th>
        <th>{{ __('Description') }}</th>
        {{-- The number the customer quotes back when they query a payment. --}}
        <th style="width:82px">{{ __('Receipt no.') }}</th>
        <th class="num" style="width:78px">{{ __('Charge') }}</th>
        <th class="num" style="width:78px">{{ __('Payment') }}</th>
        <th class="num" style="width:84px">{{ __('Balance') }}</th>
    </tr>

    {{-- Without this a statement for one month would read as though the
         account opened that morning at zero. --}}
    <tr class="open">
        <td colspan="6">{{ __('Balance brought forward') }}</td>
        <td class="num">{{ \App\Erp\Views\ValueFormat::money($opening) }}</td>
    </tr>

    @forelse ($lines as $line)
        <tr>
            <td>{{ $line['date']?->isoFormat('DD-MMM-YY') }}</td>
            <td>{{ $line['reference'] }}</td>
            <td>{{ $line['description'] }}@if ($line['method'] !== '') · {{ $line['method'] }}@endif</td>
            <td>{{ $line['receipt'] }}</td>
            <td class="num">@if ($line['charge'] > 0){{ \App\Erp\Views\ValueFormat::money($line['charge']) }}@endif</td>
            <td class="num settled">@if ($line['payment'] > 0){{ \App\Erp\Views\ValueFormat::money($line['payment']) }}@endif</td>
            <td class="num">{{ \App\Erp\Views\ValueFormat::money($line['balance']) }}</td>
        </tr>
    @empty
        <tr><td colspan="7" style="padding:16px 4px; text-align:center" class="muted">{{ __('Nothing on this account for the period.') }}</td></tr>
    @endforelse

    <tr class="close">
        <td colspan="4">{{ __('Closing balance') }}</td>
        <td class="num">{{ \App\Erp\Views\ValueFormat::money($charged) }}</td>
        <td class="num">{{ \App\Erp\Views\ValueFormat::money($paid) }}</td>
        <td class="num {{ $closing > 0 ? 'owed' : 'settled' }}">{{ \App\Erp\Views\ValueFormat::money($closing) }}</td>
    </tr>
</table>

@if ($closing > 0)
    <p class="owed" style="margin-top:12px"><b>{{ __('Amount due') }}: {{ \App\Erp\Views\ValueFormat::money($closing) }}</b></p>
@else
    <p class="settled" style="margin-top:12px"><b>{{ __('Account settled — nothing outstanding.') }}</b></p>
@endif

<x-document-footer />

</body>
</html>
