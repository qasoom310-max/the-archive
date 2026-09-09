{{-- The quotation as the customer receives it — same visual family as the
     invoice/receipt reference template: a solid #FFC837 band across the top,
     a large plain "QUOTATION" title with Quotation No./Date/Prepared by/
     Contact no. as small label/value columns beside it, a two-column
     From / Quotation for, and a plain-ruled item table.

     Table-based layout with inline styles because DomPDF supports neither
     flexbox nor grid. This file carries its own complete <style> block
     rather than the shared `<x-pdf-styles />` component, exactly like
     `invoice-pdf.blade.php` and `receipt-pdf.blade.php`.

     Item-table columns (Service / Vehicle Type / From / To / Days-Trips /
     Unit / Rate per Unit / Amount) and the Requested By line + Subtotal/
     Discount/VAT/Total box match the fields the owner pointed at on the old
     system's printed quotation — summoned from our own leg data rather than
     copied wholesale, the same way the invoice's item table was. --}}
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ __('Quotation') }} {{ $reference }}</title>
    <style>
        @page { margin: 30px 34px 60px; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 0; font-family: 'DejaVu Sans', sans-serif; color: #111827; font-size: 10.5px; line-height: 1.55; }

        {{-- Bled to the true page edge via NEGATIVE margins matching the
             @page margin above — zeroing the page margin instead would
             silently break the fixed-position <x-document-footer /> below. --}}
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

        .requested-by { margin-top: 10px; font-size: 9.5px; color: #374151; }
        .requested-by b { color: #111827; }

        table.summary { width: 240px; margin-top: 12px; }
        table.summary td { padding: 5px 0; font-size: 10px; }
        table.summary td.k { color: #374151; }
        table.summary td.v { text-align: right; }
        table.summary tr.final td { border-top: 1px solid #9ca3af; font-weight: bold; font-size: 12px; padding-top: 9px; }

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
                <div class="doc-title">{{ __('Quotation') }}</div>
            </td>
            <td class="meta-cell">
                <div class="meta-label">{{ __('Quotation No.') }}</div>
                <div class="meta-value">{{ $reference }}</div>
            </td>
            <td class="meta-cell">
                <div class="meta-label">{{ __('Date') }}</div>
                <div class="meta-value">{{ $quoteDate }}</div>
            </td>
            @if ($preparedBy !== '')
                <td class="meta-cell">
                    <div class="meta-label">{{ __('Prepared by') }}</div>
                    <div class="meta-value">{{ $preparedBy }}</div>
                </td>
            @endif
            @if ($contactNumber !== '')
                <td class="meta-cell">
                    <div class="meta-label">{{ __('Contact No.') }}</div>
                    <div class="meta-value">{{ $contactNumber }}</div>
                </td>
            @endif
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
                <div class="bill-label">{{ __('Quotation for') }}</div>
                <div class="bill-name">{{ $customerName !== '' ? $customerName : '—' }}</div>
                @if ($customerPhone !== '')<div class="bill-line">{{ $customerPhone }}</div>@endif
                @if ($contactPerson !== '')<div class="bill-line">{{ __('Contact person') }}: {{ $contactPerson }}</div>@endif
                @if ($validUntil !== '')<div class="bill-line">{{ __('Valid until') }}: {{ $validUntil }}</div>@endif
            </td>
        </tr>
    </table>

    @php
        $hasVehicle = collect($lines)->contains(fn (array $line): bool => trim((string) $line['vehicle']) !== '');
    @endphp
    <table class="items">
        <thead>
            <tr>
                <th style="width:22px">{{ __('No.') }}</th>
                <th>{{ __('Service') }}</th>
                @if ($hasVehicle)
                    <th style="width:70px">{{ __('Vehicle Type') }}</th>
                @endif
                <th>{{ __('From') }}</th>
                <th>{{ __('To') }}</th>
                <th class="num" style="width:56px">{{ __('Days/Trips') }}</th>
                <th class="num" style="width:40px">{{ __('Unit') }}</th>
                <th class="num" style="width:72px">{{ __('Rate per Unit') }}</th>
                <th class="num" style="width:80px">{{ __('Amount') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($lines as $i => $line)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $line['service'] }}</td>
                    @if ($hasVehicle)
                        <td>{{ $line['vehicle'] }}</td>
                    @endif
                    <td>{{ $line['from'] }}</td>
                    <td>{{ $line['to'] }}</td>
                    <td class="num">{{ rtrim(rtrim(number_format((float) $line['daysTrips'], 1), '0'), '.') }}</td>
                    <td class="num">{{ rtrim(rtrim(number_format((float) $line['unit'], 1), '0'), '.') }}</td>
                    <td class="num">{{ \App\Erp\Views\ValueFormat::money($line['rate']) }}</td>
                    <td class="num">{{ \App\Erp\Views\ValueFormat::money($line['amount']) }}</td>
                </tr>
            @empty
                <tr>
                    <td>1</td>
                    <td colspan="{{ $hasVehicle ? 6 : 5 }}">{{ __('Limousine services') }}</td>
                    <td class="num">{{ \App\Erp\Views\ValueFormat::money($subtotal) }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    @if ($requestedBy !== '')
        <div class="requested-by">{{ __('Requested by') }}: <b>{{ $requestedBy }}</b></div>
    @endif

    <table style="width:100%; margin-top:4px">
        <tr>
            <td></td>
            <td style="width:240px">
                <table class="summary">
                    <tr>
                        <td class="k">{{ __('Subtotal') }}</td>
                        <td class="v">{{ \App\Erp\Views\ValueFormat::money($subtotal) }}</td>
                    </tr>
                    <tr>
                        <td class="k">{{ __('Discount') }}</td>
                        <td class="v">{{ \App\Erp\Views\ValueFormat::money($discount) }}</td>
                    </tr>
                    <tr>
                        <td class="k">{{ __('VAT') }}</td>
                        <td class="v">{{ \App\Erp\Views\ValueFormat::money($vat) }}</td>
                    </tr>
                    <tr class="final">
                        <td class="k">{{ __('Total') }}</td>
                        <td class="v">{{ \App\Erp\Views\ValueFormat::money($total) }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    @if ($notes !== '')
        <p class="doc-note"><b>{{ __('Notes') }}:</b> {{ $notes }}</p>
    @endif

    <p class="doc-note">{{ __('Prices are in Bahraini Dinar. This quotation is valid until the date shown above.') }}</p>

</div>

<x-document-footer />

</body>
</html>
