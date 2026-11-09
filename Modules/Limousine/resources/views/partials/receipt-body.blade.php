{{-- One receipt's page, shared by receipt-pdf and receipts-batch-pdf. --}}
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
                @if (($customerVat ?? '') !== '')<div class="bill-line">{{ __('VAT No.') }}: {{ $customerVat }}</div>@endif
                @if ($invoiceReference !== '')<div class="bill-line">{{ __('Against invoice') }}: {{ $invoiceReference }}</div>@endif
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th style="width:80px">{{ __('Date') }}</th>
                <th style="width:70px">{{ __('Paid by') }}</th>
                {{-- What the money was for, trip by trip, so the customer can
                     tie the payment to the journey without the invoice. --}}
                <th>{{ __('Trip details') }}</th>
                <th class="num" style="width:90px">{{ __('Amount') }}</th>
            </tr>
        </thead>
        <tbody>
            {{-- One row per trip, so a booking of many trips breaks across
                 pages. All of them in a single row could not be split: the PDF
                 pushed the whole row to a later page (leaving the first pages
                 blank) and cut off whatever did not fit. Date, method and the
                 amount sit on the first row only. --}}
            @forelse ($trips as $trip)
                <tr class="{{ $loop->last ? '' : 'trip-row' }}">
                    <td>{{ $loop->first ? $date : '' }}</td>
                    <td>{{ $loop->first ? $method : '' }}</td>
                    <td class="trips">
                        <b>@if ($trip['reference'] !== '')#{{ $trip['reference'] }}@endif{{ $trip['reference'] !== '' && $trip['when'] !== '' ? ' · ' : '' }}{{ $trip['when'] }}</b>
                        @if ($trip['route'] !== '')<div>{{ $trip['route'] }}</div>@endif
                        @if ($trip['car'] !== '')<div class="trip-car">{{ $trip['car'] }}</div>@endif
                    </td>
                    <td class="num">{{ $loop->first ? \App\Erp\Views\ValueFormat::money($amount) : '' }}</td>
                </tr>
            @empty
                <tr>
                    <td>{{ $date }}</td>
                    <td>{{ $method }}</td>
                    <td class="trips">—</td>
                    <td class="num">{{ \App\Erp\Views\ValueFormat::money($amount) }}</td>
                </tr>
            @endforelse
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
