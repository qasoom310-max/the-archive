{{-- The customer's proof of payment — same reference-template style as the
     Rental invoice/quotation and the Limousine receipt: a solid #FFC837 band
     across the top, a large plain "RECEIPT" title with Date/Rental Agreement #/
     Receipt No. as small label/value columns beside it, a two-column From /
     Received with thanks from, a ruled payment-line table, and a right-aligned
     sum-of box.

     Table-based layout with inline styles because DomPDF supports neither
     flexbox nor grid. This file carries its own complete <style> block rather
     than the shared `<x-pdf-styles />` component, exactly like the invoice
     and quotation documents.

     The fields (Receipt No. / Received with thanks from + CPR / the sum of /
     Rental Agreement # / By Cash-Cheque-Credit Card / Remarks) match what the
     owner pointed at on the old system's printed receipt. The sign-off row
     mirrors the Limousine receipt's own reasoning: a receipt is our
     acknowledgement money arrived, not a contract — the office stamps it
     rather than the customer signing it. --}}
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ __('Receipt') }} {{ $reference }}</title>
    <style>
        @page { margin: 30px 34px 60px; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 0; font-family: 'DejaVu Sans', sans-serif; color: #111827; font-size: 10.5px; line-height: 1.55; }

        .topbar { background: #FFC837; height: 40px; margin: -30px -34px 20px -34px; }
        .sheet { padding: 0 0 4px; }

        table.head-meta { width: 100%; }
        table.head-meta td { vertical-align: top; }
        .doc-title { font-size: 38px; font-weight: bold; letter-spacing: .5px; text-transform: uppercase; color: #111827; margin: 0; }
        .meta-cell { text-align: right; padding-left: 18px; width: 92px; }
        .meta-label { font-size: 7.5px; text-transform: uppercase; letter-spacing: .5px; color: #9ca3af; }
        .meta-value { font-size: 10px; color: #111827; margin-top: 3px; }

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

        table.summary { width: 240px; margin-top: 12px; }
        table.summary td { padding: 5px 0; font-size: 10px; }
        table.summary td.k { color: #374151; }
        table.summary td.v { text-align: right; }
        table.summary tr.final td { border-top: 1px solid #9ca3af; font-weight: bold; font-size: 12px; padding-top: 9px; }

        .doc-note { margin-top: 18px; font-size: 9.5px; color: #374151; line-height: 1.6; }
        .doc-note b { color: #111827; }

        {{-- signature strip: a slot to sign, a slot to stamp — same reasoning
             as the Limousine receipt (a receipt is acknowledged, not signed
             to as a contract; the office stamps it). --}}
        table.sign-row { width: 100%; margin-top: 48px; }
        table.sign-row td { padding-top: 6px; border-top: 1px solid #9ca3af; font-size: 8.5px; text-transform: uppercase; letter-spacing: .3px; color: #6b7280; }
    </style>
</head>
<body>

<div class="topbar">&nbsp;</div>

<div class="sheet">

    <table class="head-meta">
        <tr>
            <td><div class="doc-title">{{ __('Receipt') }}</div></td>
            <td class="meta-cell">
                <div class="meta-label">{{ __('Date') }}</div>
                <div class="meta-value">{{ $date }}</div>
            </td>
            @if ($agreementReference !== '')
                <td class="meta-cell">
                    <div class="meta-label">{{ __('Rental Agreement #') }}</div>
                    <div class="meta-value">{{ $agreementReference }}</div>
                </td>
            @endif
            <td class="meta-cell">
                <div class="meta-label">{{ __('Receipt No.') }}</div>
                <div class="meta-value">{{ $reference }}</div>
            </td>
        </tr>
    </table>

    <div class="rule">&nbsp;</div>

    @php
        $companyPhone = trim((string) \App\Erp\Settings\Setting::get('company.phone', ''));
    @endphp
    <table style="width:100%">
        <tr>
            <td style="width:48%">
                <div class="bill-label">{{ __('From') }}</div>
                @if ($logoPath)
                    <img class="bill-logo" src="{{ $logoPath }}" alt="{{ $companyName }}"><br>
                @endif
                <div class="bill-name">{{ $companyName }}</div>
                @if ($companyPhone !== '')<div class="bill-line">{{ $companyPhone }}</div>@endif
            </td>
            <td style="width:4%">&nbsp;</td>
            <td style="width:48%; text-align:right">
                <div class="bill-label">{{ __('Received with thanks from') }}</div>
                <div class="bill-name">
                    {{ $customerName !== '' ? $customerName : '—' }}{{ $customerCpr !== '' ? ', ' . $customerCpr : '' }}
                </div>
                @if ($customerPhone !== '')<div class="bill-line">{{ $customerPhone }}</div>@endif
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th style="width:100px">{{ __('Date') }}</th>
                <th>{{ __('By Cash/Cheque/Credit Card') }}</th>
                <th class="num" style="width:110px">{{ __('Amount') }}</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ $date }}</td>
                <td>{{ $method }}</td>
                <td class="num">{{ \App\Erp\Views\ValueFormat::money($amount) }}</td>
            </tr>
        </tbody>
    </table>

    <table style="width:100%; margin-top:4px">
        <tr>
            <td></td>
            <td style="width:240px">
                <div class="bill-label">{{ __('The sum of') }}</div>
                <table class="summary">
                    @if ($showBreakdown)
                        <tr>
                            <td class="k">{{ __('Rental') }}</td>
                            <td class="v">{{ \App\Erp\Views\ValueFormat::money($principal) }}</td>
                        </tr>
                        <tr>
                            <td class="k">{{ __('VAT :rate%', ['rate' => rtrim(rtrim(number_format($vatRate, 1), '0'), '.')]) }}</td>
                            <td class="v">{{ \App\Erp\Views\ValueFormat::money($vatAmount) }}</td>
                        </tr>
                        @if ($extra > 0)
                            <tr>
                                <td class="k">{{ __('Extra charge') }}</td>
                                <td class="v">{{ \App\Erp\Views\ValueFormat::money($extra) }}</td>
                            </tr>
                        @endif
                    @endif
                    <tr class="final">
                        <td class="k">{{ __('Amount received') }}</td>
                        <td class="v">{{ \App\Erp\Views\ValueFormat::money($amount) }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    @if ($notes !== '')
        <p class="doc-note"><b>{{ __('Remarks') }}:</b> {{ $notes }}</p>
    @endif

    <table class="sign-row">
        <tr>
            <td style="width:50%">{{ __('Received by') }}</td>
            <td style="width:50%">{{ __('Stamp') }}</td>
        </tr>
    </table>

</div>

<x-document-footer />

</body>
</html>
