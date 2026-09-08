{{-- Many bills as one document. Landscape, because nine columns of trip detail
     do not fit a portrait page without shrinking the type past reading size.

     The row is a TRIP, not an invoice: what a customer reconciles against is
     the journey — who travelled, which car, where to, when — and an invoice
     number tells them nothing about the day their guest was collected. The
     invoices covered are named in the header so it still ties back. --}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ __('Invoice') }} — {{ $customer->name ?? '' }}</title>
    <x-pdf-styles />
    <style>
        @page { margin: 22px 24px 60px; }
        body { font-size: 8.5px; }
        table.doc-table th { font-size: 7.5px; }
        table.doc-totals .grand td { font-size: 11px; }
    </style>
</head>
<body>

<table class="doc-head">
    <tr>
        <td style="width:55%">
            @if ($logoPath)
                <img src="{{ $logoPath }}" style="height:{{ (int) round(38 * $logoScale / 100) }}px" alt="{{ $companyName }}">
            @else
                <div class="doc-brand-fallback">{{ $companyName }}</div>
            @endif
        </td>
        <td class="doc-title-block">
            <div class="doc-title">{{ __('Invoice') }}</div>
            <div class="doc-ref">{{ __('Invoices covered') }}: <b>{{ implode(', ', $references) }}</b></div>
            <div class="doc-sub">{{ __('Issued') }}: {{ now()->isoFormat('DD-MMM-YYYY') }}</div>
        </td>
    </tr>
</table>
<div class="doc-accent">&nbsp;</div>

<table class="doc-meta">
    <tr>
        <td class="k" style="width:92px">{{ __('Billed to') }}</td>
        <td class="v">{{ $customer->name ?? '—' }}@if ($customer?->phone) · {{ $customer->phone }}@endif</td>
    </tr>
    <tr>
        <td class="k" style="width:92px">{{ __('Period') }}</td>
        <td class="v">{{ $periodFrom?->isoFormat('DD-MMM-YYYY') }}@if ($periodTo && $periodTo->ne($periodFrom)) — {{ $periodTo->isoFormat('DD-MMM-YYYY') }}@endif</td>
    </tr>
</table>

<table class="doc-table" style="margin-top:10px">
    <thead>
        <tr>
            <th style="width:26px">{{ __('Sl') }}</th>
            <th style="width:62px">{{ __('Booking #') }}</th>
            <th style="width:74px">{{ __('Service') }}</th>
            <th style="width:76px">{{ __('Car Type') }}</th>
            <th>{{ __('From') }}</th>
            <th>{{ __('To') }}</th>
            <th style="width:82px">{{ __('Service date') }}</th>
            <th style="width:78px">{{ __('Company ref.') }}</th>
            <th style="width:88px">{{ __('Pax name') }}</th>
            <th class="num" style="width:66px">{{ __('Total') }}</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($rows as $row)
            <tr>
                <td>{{ $row['serial'] }}</td>
                <td>{{ $row['booking'] }}</td>
                <td>{{ $row['service'] }}</td>
                <td>{{ $row['vehicle'] }}</td>
                <td>{{ $row['from'] }}</td>
                <td>{{ $row['to'] }}</td>
                <td>{{ $row['date'] }}</td>
                <td>{{ $row['company_reference'] }}</td>
                <td>{{ $row['pax'] }}</td>
                <td class="num">{{ \App\Erp\Views\ValueFormat::money($row['total']) }}</td>
            </tr>
        @empty
            <tr><td colspan="10" style="padding:14px 3px; text-align:center" class="muted">{{ __('Nothing to bill.') }}</td></tr>
        @endforelse
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
                @if ($paid > 0)
                    <tr>
                        <td class="k">{{ __('Paid') }}</td>
                        <td class="v settled">− {{ \App\Erp\Views\ValueFormat::money($paid) }}</td>
                    </tr>
                @endif
                <tr>
                    <td class="k">{{ __('Balance due') }}</td>
                    <td class="v {{ $balance > 0 ? 'owed' : 'settled' }}">{{ \App\Erp\Views\ValueFormat::money($balance) }}</td>
                </tr>
            </table>
        </td>
    </tr>
</table>

<x-document-footer />

</body>
</html>
