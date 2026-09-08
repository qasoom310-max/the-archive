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
                <div class="doc-logo-chip"><img src="{{ $logoPath }}" style="height:{{ (int) round(40 * $logoScale / 100) }}px" alt="{{ $companyName }}"></div>
            @else
                <div class="doc-brand-fallback">{{ $companyName }}</div>
            @endif
        </td>
        <td class="doc-title-block">
            <div class="doc-title">{{ __('Invoice') }}</div>
            <div class="doc-ref">{{ __('Invoice no.') }}: <b>{{ $reference }}</b></div>
            <div class="doc-sub">{{ __('Issue date') }}: {{ $issueDate }}@if ($dueDate !== '') · {{ __('Due date') }}: {{ $dueDate }}@endif</div>
            @php
                $statusClass = ['unpaid' => 'doc-badge-unpaid', 'partial' => 'doc-badge-partial', 'paid' => 'doc-badge-paid'][$status] ?? '';
            @endphp
            @if ($statusClass !== '')
                <div><span class="doc-badge {{ $statusClass }}">{{ __(ucfirst($status)) }}</span></div>
            @endif
        </td>
    </tr>
</table>
<div class="doc-accent">&nbsp;</div>

<table class="doc-meta">
    <tr>
        <td class="k">{{ __('Billed to') }}</td>
        <td class="v">{{ $customerName !== '' ? $customerName : '—' }}@if ($customerPhone !== '') · {{ $customerPhone }}@endif</td>
    </tr>
    @if ($bookingReference !== '')
        <tr>
            <td class="k">{{ __('For booking') }}</td>
            <td class="v">{{ $bookingReference }}</td>
        </tr>
    @endif
    @if ($quotationReference !== '')
        <tr>
            <td class="k">{{ __('From quotation') }}</td>
            <td class="v">{{ $quotationReference }}</td>
        </tr>
    @endif
</table>

{{-- The journeys behind the figure. A bill stating one number and no trips is
     one the customer has to ring up to understand. --}}
<table class="doc-table" style="margin-top:16px">
    <thead>
        <tr>
            <th>{{ __('Description') }}</th>
            <th style="width:130px">{{ __('Date') }}</th>
            <th class="num" style="width:100px">{{ __('Amount') }}</th>
        </tr>
    </thead>
    <tbody>
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
    </tbody>
</table>

<table style="width:100%; margin-top:6px">
    <tr>
        <td></td>
        <td style="width:230px">
            <table class="doc-totals">
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
        </td>
    </tr>
</table>

@if ($balance <= 0)
    <p class="settled" style="margin-top:6px">{{ __('Paid in full — nothing further owed.') }}</p>
@endif

@if ($notes !== '')
    <p class="doc-note"><b>{{ __('Notes') }}:</b> {{ $notes }}</p>
@endif

<x-document-footer />

</body>
</html>
