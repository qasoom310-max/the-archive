{{-- One invoice's printable content — the topbar band + the whole sheet.
     Shared by the single-invoice download (`invoice-pdf.blade.php`, one of
     these on its own page) and the batch download
     (`invoices-batch-pdf.blade.php`, several of these back to back, one per
     ticked row). Expects the same view-data keys `LimoInvoicePdf::viewData()`
     always returns. --}}
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
