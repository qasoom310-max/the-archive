{{-- The customer's copy of a payment — redesigned to match the invoice's
     reference-template style: a solid pastel band across the very top,
     a large plain "RECEIPT" title with date/booking #/receipt number as
     small label/value columns beside it, a two-column From / Received from,
     a plain-ruled payment-line table, and a right-aligned amount box.

     Table-based layout with inline styles because DomPDF supports neither
     flexbox nor grid. Same visual family as `invoice-pdf.blade.php` — this
     file carries its own complete <style> block rather than the shared
     `<x-pdf-styles />` component (a different, older visual language).

     The AMOUNT RECEIVED is still the headline — that is what the customer
     handed over and what they are keeping this for. What is still owed
     sits under it, stated plainly rather than left to be worked out. --}}
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ __('Receipt') }} {{ $reference }}</title>
    <style>
        @page { margin: 30px 34px 60px; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 0; font-family: 'DejaVu Sans', sans-serif; color: #111827; font-size: 10.5px; line-height: 1.55; }

        {{-- Bled to the true page edge via NEGATIVE margins matching the
             @page margin above, exactly like the invoice — zeroing the page
             margin instead would silently break the fixed-position
             <x-document-footer /> below. --}}
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
        .owed { color: #b91c1c; }
        .settled { color: #15803d; }

        .doc-note { margin-top: 26px; font-size: 9.5px; color: #374151; line-height: 1.6; }
        .doc-note b { color: #111827; }

        {{-- The signature strip of the pad this replaced: a slot to sign, a
             slot to stamp, and — when we know it — the name of whoever
             raised it. A receipt is our acknowledgement that the money
             arrived, so the second slot is the company stamp, not the
             customer's signature — the customer is not attesting to
             anything by being paid up. --}}
        table.sign-row { width: 100%; margin-top: 48px; }
        table.sign-row td { padding-top: 6px; border-top: 1px solid #9ca3af; font-size: 8.5px; text-transform: uppercase; letter-spacing: .3px; color: #6b7280; }
        table.sign-row td.prepared { text-align: right; text-transform: none; letter-spacing: normal; }
        table.sign-row td.prepared b { color: #111827; }
    </style>
</head>
<body>

<div class="topbar">&nbsp;</div>

<div class="sheet">

    <table class="head-meta">
        <tr>
            <td>
                <div class="doc-title">{{ __('Receipt') }}</div>
            </td>
            <td class="meta-cell">
                <div class="meta-label">{{ __('Date') }}</div>
                <div class="meta-value">{{ $date }}</div>
            </td>
            @if ($bookingReference !== '')
                <td class="meta-cell">
                    <div class="meta-label">{{ __('Booking #') }}</div>
                    <div class="meta-value">{{ $bookingReference }}</div>
                </td>
            @endif
            <td class="meta-cell">
                <div class="meta-label">{{ __('Receipt no.') }}</div>
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
                <div class="bill-label">{{ __('Received from') }}</div>
                <div class="bill-name">{{ $customerName !== '' ? $customerName : '—' }}</div>
                @if ($customerPhone !== '')<div class="bill-line">{{ $customerPhone }}</div>@endif
                @if ($invoiceReference !== '')<div class="bill-line">{{ __('Against invoice') }}: {{ $invoiceReference }}</div>@endif
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th style="width:100px">{{ __('Date') }}</th>
                <th>{{ __('Paid by') }}</th>
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
                <table class="summary">
                    <tr class="final">
                        <td class="k">{{ __('Amount received') }}</td>
                        <td class="v">{{ \App\Erp\Views\ValueFormat::money($amount) }}</td>
                    </tr>
                    {{-- Stated, not implied. A part payment that says only what
                         was taken leaves the customer to work out what they
                         still owe. --}}
                    @if ($balance !== null && $balance > 0)
                        <tr>
                            <td class="k">{{ __('Balance still to pay') }}</td>
                            <td class="v owed">{{ \App\Erp\Views\ValueFormat::money($balance) }}</td>
                        </tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>

    @if ($balance !== null && $balance <= 0)
        <p class="settled" style="margin-top:8px; font-weight:bold">{{ __('Paid in full — nothing further owed.') }}</p>
    @endif

    @if ($notes !== '')
        <p class="doc-note"><b>{{ __('Note') }}:</b> {{ $notes }}</p>
    @endif

    <table class="sign-row">
        <tr>
            <td style="width:33%">{{ __('Received by') }}</td>
            <td style="width:33%">{{ __('Stamp') }}</td>
            <td style="width:34%" class="prepared">
                @if ($preparedBy !== '')
                    {{ __('Prepared by') }}: <b>{{ $preparedBy }}</b>
                @endif
            </td>
        </tr>
    </table>

</div>

<x-document-footer />

</body>
</html>
