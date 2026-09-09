{{-- One invoice's printable content — the topbar band + the whole sheet.
     Shared by the single-invoice download (`invoice-pdf.blade.php`, one of
     these on its own page) and the batch download
     (`invoices-batch-pdf.blade.php`, several of these back to back, one per
     ticked row). Expects the same view-data keys `RentalInvoicePdf::viewData()`
     always returns. Same visual family as the Limousine invoice — see
     `Modules/Limousine/resources/views/partials/invoice-body.blade.php`. --}}
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
            @if ($vatRegistrationNo !== '')
                <td class="meta-cell">
                    <div class="meta-label">{{ __('VAT Regist. No.') }}</div>
                    <div class="meta-value">{{ $vatRegistrationNo }}</div>
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
                <div class="bill-label">{{ __('Bill from') }}</div>
                @if ($logoPath)
                    <img class="bill-logo" src="{{ $logoPath }}" alt="{{ $companyName }}"><br>
                @endif
                <div class="bill-name">{{ $companyName }}</div>
                @if ($companyPhone !== '')<div class="bill-line">{{ $companyPhone }}</div>@endif
            </td>
            <td style="width:4%">&nbsp;</td>
            <td style="width:48%; text-align:right">
                <div class="bill-label">{{ __('Bill to') }}</div>
                <div class="bill-name">{{ $customerName !== '' ? $customerName : '—' }}</div>
                @if ($customerPhone !== '')<div class="bill-line">{{ $customerPhone }}</div>@endif
                @if ($orderReference !== '')<div class="bill-line">{{ __('For order') }}: {{ $orderReference }}</div>@endif
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th style="width:60px">{{ __('Service') }}</th>
                <th style="width:110px">{{ __('Vehicle') }}</th>
                <th>{{ __('From') }}</th>
                <th>{{ __('To') }}</th>
                <th style="width:64px">{{ __('Days/Trips') }}</th>
                <th class="num" style="width:80px">{{ __('Rate per Unit') }}</th>
                <th class="num" style="width:80px">{{ __('Amount') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($lines as $line)
                <tr>
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
                    {{-- Discount always prints (even 0.000), matching the
                         owner's reference invoice — unlike the Limousine
                         invoice, which hides it when there is none. --}}
                    <tr>
                        <td class="k">{{ __('Discount') }}</td>
                        <td class="v">{{ \App\Erp\Views\ValueFormat::money($discount) }}</td>
                    </tr>
                    @if ($deliveryCharges > 0)
                        <tr>
                            <td class="k">{{ __('Delivery') }}</td>
                            <td class="v">{{ \App\Erp\Views\ValueFormat::money($deliveryCharges) }}</td>
                        </tr>
                    @endif
                    @if ($extraCharge > 0)
                        <tr>
                            <td class="k">{{ __('Extra charge') }}</td>
                            <td class="v">{{ \App\Erp\Views\ValueFormat::money($extraCharge) }}</td>
                        </tr>
                    @endif
                    @if ($fuelCharge > 0)
                        <tr>
                            <td class="k">{{ __('Fuel / service charge') }}</td>
                            <td class="v">{{ \App\Erp\Views\ValueFormat::money($fuelCharge) }}</td>
                        </tr>
                    @endif
                    <tr>
                        <td class="k">{{ __('VAT :rate%', ['rate' => rtrim(rtrim(number_format($vatRate, 1), '0'), '.')]) }}</td>
                        <td class="v">{{ \App\Erp\Views\ValueFormat::money($vatAmount) }}</td>
                    </tr>
                    <tr>
                        <td class="k">{{ __('Total') }}</td>
                        <td class="v">{{ \App\Erp\Views\ValueFormat::money($total) }}</td>
                    </tr>
                    {{-- Received always prints too, same reasoning as Discount. --}}
                    <tr>
                        <td class="k">{{ __('Received') }}</td>
                        <td class="v {{ $paid > 0 ? 'settled' : '' }}">{{ \App\Erp\Views\ValueFormat::money($paid) }}</td>
                    </tr>
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
