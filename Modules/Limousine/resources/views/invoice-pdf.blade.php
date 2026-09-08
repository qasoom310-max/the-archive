{{-- The customer's bill — redesigned to match the clean, minimal reference
     template the owner supplied: a solid pastel band across the very top,
     a large plain "INVOICE" title with issue/due date + invoice number in
     small label/value columns beside it, a two-column Bill from / Bill to,
     a plain-ruled item table, and a right-aligned totals box with the final
     row bold above a top rule.

     Table-based layout with inline styles because DomPDF supports neither
     flexbox nor grid. This document does NOT include the shared
     `<x-pdf-styles />` component — its visual language (dark letterhead,
     gold accents) is a different family from this reference design, so
     this file carries its own complete `<style>` block.

     The BALANCE is still the headline, not the total: what the customer
     wants to know from a bill is what they still owe, and on a part-paid
     invoice the total alone is misleading. --}}
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ __('Invoice') }} {{ $reference }}</title>
    <style>
        @page { margin: 0 0 60px; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body { font-family: 'DejaVu Sans', sans-serif; color: #111827; font-size: 10.5px; line-height: 1.55; }

        .topbar { background: #c7ddf0; height: 40px; }
        .sheet { padding: 24px 36px 4px; }

        table.head-meta { width: 100%; }
        table.head-meta td { vertical-align: top; }
        .doc-title { font-size: 38px; font-weight: bold; letter-spacing: .5px; text-transform: uppercase; color: #111827; margin: 0; }
        .meta-cell { text-align: right; padding-left: 18px; width: 92px; }
        .meta-label { font-size: 7.5px; text-transform: uppercase; letter-spacing: .5px; color: #9ca3af; }
        .meta-value { font-size: 10px; color: #111827; margin-top: 3px; }

        .doc-badge {
            display: inline-block; margin-top: 8px; padding: 3px 11px; font-size: 8px; font-weight: bold;
            text-transform: uppercase; letter-spacing: .5px; border-radius: 9px; color: #ffffff;
        }
        .doc-badge-paid, .doc-badge-settled { background: #16a34a; }
        .doc-badge-partial { background: #2563eb; }
        .doc-badge-unpaid, .doc-badge-void { background: #dc2626; }

        .rule { border-top: 1px solid #d1d5db; margin: 16px 0 20px; }

        .bill-label { font-size: 8.5px; text-transform: uppercase; letter-spacing: .5px; color: #9ca3af; margin-bottom: 4px; }
        .bill-name { font-weight: bold; font-size: 11px; color: #111827; }
        .bill-line { color: #374151; margin-top: 2px; }
        .bill-logo { max-height: 26px; margin-bottom: 4px; }

        table.items { width: 100%; margin-top: 26px; }
        table.items th {
            text-align: left; font-size: 9.5px; font-weight: bold; color: #111827;
            padding: 0 6px 8px; border-bottom: 1px solid #9ca3af;
        }
        table.items th.num, table.items td.num { text-align: right; }
        table.items td { padding: 9px 6px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        .ref-note { color: #6b7280; font-size: 9px; margin-top: 2px; }

        table.summary { width: 240px; margin-top: 12px; }
        table.summary td { padding: 5px 0; font-size: 10px; }
        table.summary td.k { color: #374151; }
        table.summary td.v { text-align: right; }
        table.summary tr.final td { border-top: 1px solid #9ca3af; font-weight: bold; font-size: 12px; padding-top: 9px; }
        .owed { color: #b91c1c; }
        .settled { color: #15803d; }

        .doc-note { margin-top: 26px; font-size: 9.5px; color: #374151; line-height: 1.6; }
        .doc-note b { color: #111827; }
    </style>
</head>
<body>

<div class="topbar">&nbsp;</div>

<div class="sheet">

    <table class="head-meta">
        <tr>
            <td>
                <div class="doc-title">{{ __('Invoice') }}</div>
                @php
                    $statusClass = ['unpaid' => 'doc-badge-unpaid', 'partial' => 'doc-badge-partial', 'paid' => 'doc-badge-paid'][$status] ?? '';
                @endphp
                @if ($statusClass !== '')
                    <div><span class="doc-badge {{ $statusClass }}">{{ __(ucfirst($status)) }}</span></div>
                @endif
            </td>
            <td class="meta-cell">
                <div class="meta-label">{{ __('Issue date') }}</div>
                <div class="meta-value">{{ $issueDate }}</div>
            </td>
            @if ($dueDate !== '')
                <td class="meta-cell">
                    <div class="meta-label">{{ __('Due date') }}</div>
                    <div class="meta-value">{{ $dueDate }}</div>
                </td>
            @endif
            <td class="meta-cell">
                <div class="meta-label">{{ __('Invoice no.') }}</div>
                <div class="meta-value">{{ $reference }}</div>
            </td>
        </tr>
    </table>

    <div class="rule">&nbsp;</div>

    @php
        $companyAddress = trim((string) \App\Erp\Settings\Setting::get('company.address', ''));
        $companyPhone = trim((string) \App\Erp\Settings\Setting::get('company.phone', ''));
    @endphp
    <table style="width:100%">
        <tr>
            <td style="width:48%">
                <div class="bill-label">{{ __('Bill from') }}</div>
                @if ($logoPath)
                    <img class="bill-logo" src="{{ $logoPath }}" alt="{{ $companyName }}"><br>
                @else
                    <div class="bill-name">{{ $companyName }}</div>
                @endif
                @if ($companyAddress !== '')<div class="bill-line">{{ $companyAddress }}</div>@endif
                @if ($companyPhone !== '')<div class="bill-line">{{ $companyPhone }}</div>@endif
            </td>
            <td style="width:4%">&nbsp;</td>
            <td style="width:48%; text-align:right">
                <div class="bill-label">{{ __('Bill to') }}</div>
                <div class="bill-name">{{ $customerName !== '' ? $customerName : '—' }}</div>
                @if ($customerPhone !== '')<div class="bill-line">{{ $customerPhone }}</div>@endif
                @if ($bookingReference !== '')<div class="bill-line">{{ __('For booking') }}: {{ $bookingReference }}</div>@endif
                @if ($quotationReference !== '')<div class="bill-line">{{ __('From quotation') }}: {{ $quotationReference }}</div>@endif
            </td>
        </tr>
    </table>

    {{-- The journeys behind the figure. A bill stating one number and no trips is
         one the customer has to ring up to understand. --}}
    <table class="items">
        <thead>
            <tr>
                <th style="width:100px">{{ __('Date') }}</th>
                <th>{{ __('Description') }}</th>
                <th class="num" style="width:100px">{{ __('Amount') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($lines as $line)
                <tr>
                    <td>{{ $line['when'] }}</td>
                    <td>{{ $line['description'] }}</td>
                    <td class="num">{{ \App\Erp\Views\ValueFormat::money($line['amount']) }}</td>
                </tr>
            @empty
                <tr>
                    <td></td>
                    <td>{{ __('Limousine services') }}</td>
                    <td class="num">{{ \App\Erp\Views\ValueFormat::money($subtotal) }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <table style="width:100%; margin-top:4px">
        <tr>
            <td></td>
            <td style="width:240px">
                <table class="summary">
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
                    <tr>
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
                    <tr class="final">
                        <td class="k">{{ __('Balance due') }}</td>
                        <td class="v {{ $balance > 0 ? 'owed' : 'settled' }}">{{ \App\Erp\Views\ValueFormat::money($balance) }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    @if ($balance <= 0)
        <p class="settled" style="margin-top:8px; font-weight:bold">{{ __('Paid in full — nothing further owed.') }}</p>
    @endif

    @if ($notes !== '')
        <p class="doc-note"><b>{{ __('Notes') }}:</b> {{ $notes }}</p>
    @endif

</div>

<x-document-footer />

</body>
</html>
