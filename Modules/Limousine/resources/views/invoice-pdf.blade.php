{{-- The customer's bill. Table layout with inline styles because DomPDF
     supports neither flexbox nor grid.

     The BALANCE is the headline, not the total: what the customer wants to know
     from a bill is what they still owe, and on a part-paid invoice the total
     alone is misleading. --}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ __('Invoice') }} {{ $reference }}</title>
    <style>
        @page { margin: 30px 34px 60px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10.5px; color: #111; }
        .logo { height: {{ (int) round(52 * $logoScale / 100) }}px; }
        .brand-fallback { background: #f5ef1a; display: inline-block; padding: 8px 18px; font-size: 22px; font-weight: bold; letter-spacing: 1px; }
        h1 { font-size: 17px; margin: 14px 0 2px; text-decoration: underline; }
        .muted { color: #666; }
        table { width: 100%; border-collapse: collapse; }
        td, th { padding: 6px 4px; vertical-align: top; }
        .lbl { font-weight: bold; width: 130px; }
        .items th { border-bottom: 2px solid #111; text-align: left; font-size: 9.5px; text-transform: uppercase; letter-spacing: .5px; }
        .items td { border-bottom: 1px solid #eee; }
        .num { text-align: right; }
        .totals { margin-top: 10px; }
        .totals td { padding: 4px; }
        .totals .k { text-align: right; color: #555; }
        .totals .v { text-align: right; width: 110px; font-weight: bold; }
        .grand td { border-top: 2px solid #111; font-size: 13px; }
        .owed { color: #b00020; font-weight: bold; }
        .settled { color: #0a7d33; font-weight: bold; }
        .detail td { border-bottom: 1px solid #eee; }
        .foot { margin-top: 26px; font-size: 9.5px; color: #333; line-height: 1.6; }
    </style>
</head>
<body>

@if ($logoPath)
    <img src="{{ $logoPath }}" class="logo" alt="{{ $companyName }}">
@else
    <div class="brand-fallback">{{ $companyName }}</div>
@endif

<h1>{{ __('Invoice') }}</h1>

<table>
    <tr>
        <td style="width:60%"><b>{{ __('Invoice no.') }}:</b> {{ $reference }}</td>
        <td style="text-align:right">{{ __('Issue date') }}: <b>{{ $issueDate }}</b></td>
    </tr>
    @if ($dueDate !== '')
        <tr>
            <td></td>
            <td style="text-align:right">{{ __('Due date') }}: <b>{{ $dueDate }}</b></td>
        </tr>
    @endif
</table>

<table class="detail" style="margin-top:8px">
    <tr>
        <td class="lbl">{{ __('Billed to') }}</td>
        <td>{{ $customerName !== '' ? $customerName : '—' }}@if ($customerPhone !== '') · {{ $customerPhone }}@endif</td>
    </tr>
    @if ($bookingReference !== '')
        <tr>
            <td class="lbl">{{ __('For booking') }}</td>
            <td>{{ $bookingReference }}</td>
        </tr>
    @endif
    @if ($quotationReference !== '')
        <tr>
            <td class="lbl">{{ __('From quotation') }}</td>
            <td>{{ $quotationReference }}</td>
        </tr>
    @endif
</table>

{{-- The journeys behind the figure. A bill stating one number and no trips is
     one the customer has to ring up to understand. --}}
<table class="items" style="margin-top:16px">
    <tr>
        <th>{{ __('Description') }}</th>
        <th style="width:130px">{{ __('Date') }}</th>
        <th class="num" style="width:100px">{{ __('Amount') }}</th>
    </tr>
    @forelse ($lines as $line)
        <tr>
            <td>{{ $line['description'] }}</td>
            <td>{{ $line['when'] }}</td>
            <td class="num">{{ \App\Erp\Views\ValueFormat::money($line['amount']) }}</td>
        </tr>
    @empty
        <tr>
            <td>{{ __('Limousine services') }}</td>
            <td></td>
            <td class="num">{{ \App\Erp\Views\ValueFormat::money($subtotal) }}</td>
        </tr>
    @endforelse
</table>

<table class="totals">
    <tr>
        <td class="k">{{ __('Subtotal') }}</td>
        <td class="v">{{ \App\Erp\Views\ValueFormat::money($subtotal) }}</td>
    </tr>
    @if ($discount > 0)
        <tr>
            <td class="k">{{ __('Discount') }}</td>
            <td class="v">− {{ \App\Erp\Views\ValueFormat::money($discount) }}</td>
        </tr>
    @endif
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
    {{-- What the customer actually needs from this page. --}}
    <tr>
        <td class="k">{{ __('Balance due') }}</td>
        <td class="v {{ $balance > 0 ? 'owed' : 'settled' }}">{{ \App\Erp\Views\ValueFormat::money($balance) }}</td>
    </tr>
</table>

@if ($balance <= 0)
    <p class="settled" style="margin-top:10px">{{ __('Paid in full — nothing further owed.') }}</p>
@endif

@if ($notes !== '')
    <p class="muted" style="margin-top:16px"><b>{{ __('Notes') }}:</b> {{ $notes }}</p>
@endif

<x-document-footer />

</body>
</html>
