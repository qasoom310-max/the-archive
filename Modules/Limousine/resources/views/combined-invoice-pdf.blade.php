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
    <style>
        @page { margin: 22px 24px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 8.5px; color: #111; }
        .logo { height: {{ (int) round(44 * $logoScale / 100) }}px; }
        .brand-fallback { background: #f5ef1a; display: inline-block; padding: 6px 14px; font-size: 18px; font-weight: bold; letter-spacing: 1px; }
        h1 { font-size: 15px; margin: 10px 0 2px; text-decoration: underline; }
        .muted { color: #666; }
        table { width: 100%; border-collapse: collapse; }
        td, th { padding: 4px 3px; vertical-align: top; }
        .head td { padding: 1px 3px; }
        .lbl { font-weight: bold; width: 92px; }
        .items th { border-bottom: 2px solid #111; text-align: left; font-size: 7.5px; text-transform: uppercase; letter-spacing: .3px; }
        .items td { border-bottom: 1px solid #eee; }
        .items tr:nth-child(even) td { background: #fafafa; }
        .num { text-align: right; white-space: nowrap; }
        .totals { margin-top: 8px; }
        .totals .k { text-align: right; color: #555; }
        .totals .v { text-align: right; width: 110px; font-weight: bold; }
        .grand td { border-top: 2px solid #111; font-size: 11px; }
        .owed { color: #b00020; }
        .settled { color: #0a7d33; }
        .foot { margin-top: 16px; font-size: 8px; color: #333; }
    </style>
</head>
<body>

@if ($logoPath)
    <img src="{{ $logoPath }}" class="logo" alt="{{ $companyName }}">
@else
    <div class="brand-fallback">{{ $companyName }}</div>
@endif

<h1>{{ __('Invoice') }}</h1>

<table class="head">
    <tr>
        <td class="lbl">{{ __('Billed to') }}</td>
        <td>{{ $customer->name ?? '—' }}@if ($customer?->phone) · {{ $customer->phone }}@endif</td>
    </tr>
    <tr>
        <td class="lbl">{{ __('Period') }}</td>
        <td>{{ $periodFrom?->isoFormat('DD-MMM-YYYY') }}@if ($periodTo && $periodTo->ne($periodFrom)) — {{ $periodTo->isoFormat('DD-MMM-YYYY') }}@endif</td>
    </tr>
    <tr>
        <td class="lbl">{{ __('Issued') }}</td>
        <td>{{ now()->isoFormat('DD-MMM-YYYY') }}</td>
    </tr>
    {{-- So the one document still ties back to what was billed. --}}
    <tr>
        <td class="lbl">{{ __('Invoices covered') }}</td>
        <td>{{ implode(', ', $references) }}</td>
    </tr>
</table>

<table class="items" style="margin-top:10px">
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
</table>

<table class="totals">
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

<div class="foot">
    {{ $companyName }}@if ($companyPhone !== '') · {{ $companyPhone }}@endif@if ($companyEmail !== '') · {{ $companyEmail }}@endif
</div>

</body>
</html>
