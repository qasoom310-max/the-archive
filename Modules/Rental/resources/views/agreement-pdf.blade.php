@php
    use App\Erp\Views\ValueFormat;
    use Modules\Rental\Models\RentalOrder;

    /** @var RentalOrder $order */
    $cust = $order->customer;
    $veh = $order->vehicle;
    $carType = trim(($veh?->make ?? '') . ' ' . ($veh?->model ?? ''));
    if ($carType === '') { $carType = (string) ($veh?->name ?? '—'); }
    $kmOut = $order->handover_km ?? $order->pickup_mileage;
@endphp
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 22px 26px; }
        * { font-family: DejaVu Sans, sans-serif; }
        body { color: #1f2937; font-size: 11px; line-height: 1.45; }
        .head { width: 100%; border-bottom: 2px solid #111; padding-bottom: 8px; }
        .head td { vertical-align: middle; }
        .logo { max-height: 52px; max-width: 150px; }
        .company { font-size: 17px; font-weight: bold; }
        .title { font-size: 15px; font-weight: bold; text-align: right; }
        .ref { text-align: right; color: #6b7280; font-size: 11px; }
        h2 { font-size: 11px; text-transform: uppercase; letter-spacing: .5px; color: #6b7280;
             margin: 14px 0 4px; border-bottom: 1px solid #e5e7eb; padding-bottom: 3px; }
        table.kv { width: 100%; border-collapse: collapse; }
        table.kv td { padding: 2px 0; vertical-align: top; }
        table.kv td.k { color: #6b7280; width: 38%; }
        table.kv td.v { font-weight: bold; }
        table.money { width: 100%; border-collapse: collapse; margin-top: 4px; }
        table.money td { padding: 3px 0; }
        table.money td.r { text-align: right; }
        .net td { border-top: 1px solid #d1d5db; font-weight: bold; font-size: 12px; padding-top: 5px; }
        .bal td { font-weight: bold; color: #b45309; }
        .col { width: 49%; vertical-align: top; }
        .terms { font-size: 9.5px; color: #374151; white-space: normal; }
        .sign td { padding-top: 34px; }
        .sign .line { border-top: 1px solid #111; padding-top: 3px; color: #6b7280; }
        .muted { color: #6b7280; }
    </style>
</head>
<body>
    <table class="head">
        <tr>
            <td>
                @if ($logoPath)<img class="logo" src="{{ $logoPath }}" alt="">@endif
                <div class="company">{{ $companyName }}</div>
            </td>
            <td>
                <div class="title">{{ __('Car Hire Agreement') }}</div>
                <div class="ref">{{ $order->reference }} · {{ optional($order->order_date)->format('d-m-Y') }}</div>
            </td>
        </tr>
    </table>

    <table style="width:100%"><tr>
        <td class="col">
            <h2>{{ __('Customer') }}</h2>
            <table class="kv">
                <tr><td class="k">{{ __('Name') }}</td><td class="v">{{ $cust?->name ?? '—' }}</td></tr>
                <tr><td class="k">{{ __('Phone') }}</td><td class="v">{{ $order->phone ?: ($cust?->phone ?? '—') }}</td></tr>
                <tr><td class="k">{{ __('CPR / ID') }}</td><td class="v">{{ $cust?->cpr ?? '—' }}</td></tr>
                <tr><td class="k">{{ __('Licence number') }}</td><td class="v">{{ $cust?->license_no ?? '—' }}</td></tr>
                <tr><td class="k">{{ __('Nationality') }}</td><td class="v">{{ $cust?->nationality ?? '—' }}</td></tr>
                <tr><td class="k">{{ __('Address') }}</td><td class="v">{{ $cust?->address ?? '—' }}</td></tr>
                @if ($order->additional_driver)
                    <tr><td class="k">{{ __('Additional driver') }}</td><td class="v">{{ $order->additional_driver }} {{ $order->additional_driver_license ? '· ' . $order->additional_driver_license : '' }}</td></tr>
                @endif
            </table>
        </td>
        <td style="width:2%"></td>
        <td class="col">
            <h2>{{ __('Vehicle') }}</h2>
            <table class="kv">
                <tr><td class="k">{{ __('Type of Car') }}</td><td class="v">{{ $carType }}</td></tr>
                <tr><td class="k">{{ __('Car No.') }}</td><td class="v">{{ $veh?->plate_no ?? '—' }}</td></tr>
                <tr><td class="k">{{ __('Year') }}</td><td class="v">{{ $veh?->year ?: '—' }}</td></tr>
                <tr><td class="k">{{ __('Colour') }}</td><td class="v">{{ $veh?->color ?: '—' }}</td></tr>
                <tr><td class="k">{{ __('Branch') }}</td><td class="v">{{ $order->branch?->name ?? '—' }}</td></tr>
            </table>
        </td>
    </tr></table>

    <table style="width:100%"><tr>
        <td class="col">
            <h2>{{ __('Rental period') }}</h2>
            <table class="kv">
                <tr><td class="k">{{ __('Pick-up date') }}</td><td class="v">{{ optional($order->start_date)->format('d-m-Y') }} {{ $order->hired_time }}</td></tr>
                <tr><td class="k">{{ __('Return date') }}</td><td class="v">{{ optional($order->end_date)->format('d-m-Y') }}</td></tr>
                <tr><td class="k">{{ __('No. of days') }}</td><td class="v">{{ $order->days }}</td></tr>
                <tr><td class="k">{{ __('Rate type') }}</td><td class="v">{{ __(ucfirst($order->rate_type)) }} · {{ ValueFormat::money($order->rate) }}</td></tr>
                @if ($kmOut !== null)<tr><td class="k">{{ __('KM') }}</td><td class="v">{{ number_format((float) $kmOut) }}</td></tr>@endif
            </table>
        </td>
        <td style="width:2%"></td>
        <td class="col">
            <h2>{{ __('Charges') }}</h2>
            <table class="money">
                <tr><td>{{ __('Amount') }}</td><td class="r">{{ ValueFormat::money($order->subtotal) }}</td></tr>
                <tr><td>{{ __('Discount') }}</td><td class="r">− {{ ValueFormat::money($order->discount) }}</td></tr>
                <tr><td>{{ __('VAT') }} ({{ rtrim(rtrim(number_format($order->vat_rate, 2), '0'), '.') }}%)</td><td class="r">+ {{ ValueFormat::money($order->vat_amount) }}</td></tr>
                <tr><td>{{ __('Delivery charges') }}</td><td class="r">+ {{ ValueFormat::money($order->delivery_charges) }}</td></tr>
                @if ($order->fuelChargeTotal() > 0)<tr><td>{{ __('Fuel charge') }}</td><td class="r">+ {{ ValueFormat::money($order->fuelChargeTotal()) }}</td></tr>@endif
                <tr class="net"><td>{{ __('Net total') }}</td><td class="r">{{ ValueFormat::money($order->total) }}</td></tr>
                <tr><td>{{ __('Advance') }}</td><td class="r">− {{ ValueFormat::money($order->advance_amount) }}</td></tr>
                <tr class="bal"><td>{{ __('Balance') }}</td><td class="r">{{ ValueFormat::money($order->balance) }}</td></tr>
                <tr><td class="muted">{{ __('Deposit (refundable)') }}</td><td class="r muted">{{ ValueFormat::money($order->deposit) }}</td></tr>
                <tr><td class="muted">{{ __('Payment type') }}</td><td class="r muted">{{ $order->payment_type ? __(ucfirst($order->payment_type)) : '—' }}</td></tr>
            </table>
        </td>
    </tr></table>

    @if (trim($terms) !== '')
        <h2>{{ __('Terms & conditions') }}</h2>
        <div class="terms">{!! nl2br(e($terms)) !!}</div>
    @endif

    <table style="width:100%" class="sign">
        <tr>
            <td style="width:48%"><div class="line">{{ __('Customer signature') }}</div></td>
            <td style="width:4%"></td>
            <td style="width:48%"><div class="line">{{ __('For') }} {{ $companyName }}</div></td>
        </tr>
    </table>
</body>
</html>
