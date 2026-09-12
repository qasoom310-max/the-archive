{{-- One quotation's printable content — the topbar band + the whole sheet.
     Shared by the single-quotation download (`quotation-pdf.blade.php`, one
     of these on its own page) and the batch download
     (`quotations-batch-pdf.blade.php`, several of these back to back, one
     per ticked row). Expects the same view-data keys
     `RentalQuotationPdf::viewData()` always returns. Same visual family as
     the Rental invoice — see `Modules/Rental/resources/views/partials/invoice-body.blade.php`.

     Item-table columns (No. / Service / Vehicle / From / To / Days / Rate /
     Amount) and the Requirements box match the fields the owner pointed at
     on the old system's printed quotation. --}}
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
                @if ($validUntil !== '')<div class="bill-line">{{ __('Valid until') }}: {{ $validUntil }}</div>@endif
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th style="width:22px">{{ __('No.') }}</th>
                <th>{{ __('Service') }}</th>
                <th style="width:110px">{{ __('Vehicle') }}</th>
                <th>{{ __('From') }}</th>
                <th>{{ __('To') }}</th>
                <th style="width:48px">{{ __('Days') }}</th>
                <th class="num" style="width:72px">{{ __('Rate') }}</th>
                <th class="num" style="width:80px">{{ __('Amount') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($lines as $i => $line)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $line['service'] }}</td>
                    <td>{{ $line['vehicle'] }}</td>
                    <td>{{ $line['from'] }}</td>
                    <td>{{ $line['to'] }}</td>
                    <td>{{ $line['units'] }} {{ $line['unitLabel'] }}</td>
                    <td class="num">{{ \App\Erp\Views\ValueFormat::money($line['rate']) }}</td>
                    <td class="num">{{ \App\Erp\Views\ValueFormat::money($line['amount']) }}</td>
                </tr>
            @empty
                <tr>
                    <td>1</td>
                    <td colspan="6">{{ __('Rental services') }}</td>
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
                    <tr>
                        <td class="k">{{ __('Discount') }}</td>
                        <td class="v">{{ \App\Erp\Views\ValueFormat::money($discount) }}</td>
                    </tr>
                    <tr>
                        <td class="k">{{ __('VAT :rate%', ['rate' => rtrim(rtrim(number_format($vatRate, 1), '0'), '.')]) }}</td>
                        <td class="v">{{ \App\Erp\Views\ValueFormat::money($vatAmount) }}</td>
                    </tr>
                    <tr class="final">
                        <td class="k">{{ __('Total') }}</td>
                        <td class="v">{{ \App\Erp\Views\ValueFormat::money($total) }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    @if ($deposit > 0)
        <p class="doc-note">
            {{ __('Requirements are the following: :deposit deposit (security deposit will be refunded within 7 working days from the date of returning the car), a copy of the driving licence, and a copy of ID or passport.', ['deposit' => \App\Erp\Views\ValueFormat::money($deposit)]) }}
        </p>
    @endif

    @if ($notes !== '')
        <p class="doc-note"><b>{{ __('Notes') }}:</b> {{ $notes }}</p>
    @endif

    <p class="doc-note">{{ __('Prices are in Bahraini Dinar. This quotation is valid until the date shown above.') }}</p>

</div>
