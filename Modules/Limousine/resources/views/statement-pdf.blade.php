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
    <x-pdf-styles />
    <style>
        @page { margin: 28px 30px 60px; }
        .ledger th { font-size: 8.5px; }
        /* `.ledger th` is the more specific selector, so without this the
           money HEADINGS stayed left-aligned over right-aligned figures and
           no column read as a column. */
        .ledger th.num { text-align: right; }
        .ledger .open td { background: #f8fafc; font-weight: bold; }
        .ledger .close td { border-top: 1.5px solid #0f172a; font-weight: bold; font-size: 11.5px; }
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
            <div class="doc-title">{{ __('Statement of account') }}</div>
            <div class="doc-ref">{{ __('Account') }}: <b>{{ $customer->name }}</b></div>
            <div class="doc-sub">
                {{ __('Period') }}:
                @if ($from || $to)
                    {{ $from?->isoFormat('DD-MMM-YYYY') ?? '…' }} — {{ $to?->isoFormat('DD-MMM-YYYY') ?? '…' }}
                @else
                    {{ __('All time') }}
                @endif
                · {{ __('Issued') }} {{ now()->isoFormat('DD-MMM-YYYY') }}
            </div>
        </td>
    </tr>
</table>
<div class="doc-accent">&nbsp;</div>

@if ($customer->phone)
    <table class="doc-meta">
        <tr><td class="k" style="width:80px">{{ __('Phone') }}</td><td class="v">{{ $customer->phone }}</td></tr>
    </table>
@endif

<table class="doc-table ledger" style="margin-top:14px">
    <thead>
    <tr>
        <th style="width:60px">{{ __('Date') }}</th>
        <th style="width:68px">{{ __('Reference') }}</th>
        <th>{{ __('Description') }}</th>
        {{-- The customer's own order number, so their accounts department can
             tie each line to their paperwork rather than only to ours. --}}
        <th style="width:100px">{{ __('Company ref.') }}</th>
        {{-- The number the customer quotes back when they query a payment. --}}
        <th style="width:86px">{{ __('Receipt no.') }}</th>
        <th class="num" style="width:72px">{{ __('Charge') }}</th>
        <th class="num" style="width:72px">{{ __('Payment') }}</th>
        <th class="num" style="width:80px">{{ __('Balance') }}</th>
    </tr>
    </thead>
    <tbody>
    {{-- Without this a statement for one month would read as though the
         account opened that morning at zero. --}}
    <tr class="open">
        <td colspan="7">{{ __('Balance brought forward') }}</td>
        <td class="num">{{ \App\Erp\Views\ValueFormat::money($opening) }}</td>
    </tr>

    @forelse ($lines as $line)
        <tr>
            <td>{{ $line['date']?->isoFormat('DD-MMM-YY') }}</td>
            <td>{{ $line['reference'] }}</td>
            <td>{{ $line['description'] }}@if ($line['method'] !== '') · {{ $line['method'] }}@endif</td>
            <td>{{ $line['company_reference'] }}</td>
            <td>{{ $line['receipt'] }}</td>
            <td class="num">@if ($line['charge'] > 0){{ \App\Erp\Views\ValueFormat::money($line['charge']) }}@endif</td>
            <td class="num settled">@if ($line['payment'] > 0){{ \App\Erp\Views\ValueFormat::money($line['payment']) }}@endif</td>
            <td class="num">{{ \App\Erp\Views\ValueFormat::money($line['balance']) }}</td>
        </tr>
    @empty
        <tr><td colspan="8" style="padding:16px 4px; text-align:center" class="muted">{{ __('Nothing on this account for the period.') }}</td></tr>
    @endforelse

    <tr class="close">
        <td colspan="5">{{ __('Closing balance') }}</td>
        <td class="num">{{ \App\Erp\Views\ValueFormat::money($charged) }}</td>
        <td class="num">{{ \App\Erp\Views\ValueFormat::money($paid) }}</td>
        <td class="num {{ $closing > 0 ? 'owed' : 'settled' }}">{{ \App\Erp\Views\ValueFormat::money($closing) }}</td>
    </tr>
    </tbody>
</table>

@if ($closing > 0)
    <p class="owed" style="margin-top:12px"><b>{{ __('Amount due') }}: {{ \App\Erp\Views\ValueFormat::money($closing) }}</b></p>
@else
    <p class="settled" style="margin-top:12px"><b>{{ __('Account settled — nothing outstanding.') }}</b></p>
@endif

<x-document-footer />

</body>
</html>
