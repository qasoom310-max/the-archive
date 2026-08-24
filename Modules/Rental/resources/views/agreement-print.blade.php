@php
    use Modules\Rental\Models\RentalOrder;

    /** @var RentalOrder $order */
    $cust = $order->customer;
    $veh = $order->vehicle;

    // Plain digits (the form already prints "BD"), but at the currency's own
    // precision — this was hard-coded to 3 decimals against the 2-decimal policy.
    $decimals = \App\Erp\Money\Currencies::active()->decimals;
    $money = static fn ($v): string => number_format((float) $v, $decimals);
    $intf = static fn ($v): string => $v === null || $v === '' ? '' : number_format((float) $v);

    $od = $order->order_date;
    $carType = trim(($veh?->make ?? '') . ' ' . ($veh?->model ?? ''));
    if ($carType === '') { $carType = (string) ($veh?->name ?? ''); }

    $timeOut = $order->started_at
        ? $order->started_at->format('d-m-Y h:i A')
        : trim(($order->start_date?->format('d-m-Y') ?? '') . ' ' . ($order->hired_time ?? ''));
    $timeIn = $order->returned_at?->format('d-m-Y h:i A') ?? '';

    $kmOut = $order->handover_km ?? $order->pickup_mileage;
    $kmIn = $order->return_km;
    $kmUsed = ($kmOut !== null && $kmIn !== null) ? max(0, (int) $kmIn - (int) $kmOut) : null;

    // Daily / weekly / monthly rate goes in its matching row.
    $rate = (float) $order->rate;
    $rateDay = $order->rate_type === 'daily' ? $rate : null;
    $rateWeek = $order->rate_type === 'weekly' ? $rate : null;
    $rateMonth = $order->rate_type === 'monthly' ? $rate : null;

    // Each field: [top_mm, left_mm, value, align(l|r|c), letter-spacing px].
    // These are calibration estimates — append ?grid=1 to align, then nudge.
    $F = [
        // Left column — customer
        [38, 50, $cust?->name, 'l'],
        [49, 50, $cust?->address, 'l'],
        [57, 50, $order->phone ?: ($cust?->phone), 'l'],
        // Date boxes (Day / Month / Year)
        [40, 151, $od?->format('d'), 'c', 4],
        [40, 165, $od?->format('m'), 'c', 4],
        [40, 178, $od?->format('Y'), 'c', 3],
        // Charges table (BHD column)
        [74, 88, $rateDay !== null ? $money($rateDay) : null, 'l'],
        [81, 88, $rateWeek !== null ? $money($rateWeek) : null, 'l'],
        [88, 88, $rateMonth !== null ? $money($rateMonth) : null, 'l'],
        [110, 88, $money($order->subtotal), 'l'],
        [122, 70, $intf($veh?->next_maintenance_mileage), 'l'],
        // Right column — licence + vehicle
        [70, 152, $cust?->license_no, 'l'],
        [84, 152, '', 'l'],        // Expiry date — not stored yet
        [91, 152, $carType, 'l'],
        [98, 152, $veh?->plate_no, 'l'],
        [105, 162, $timeOut, 'l'],
        [112, 162, $timeIn, 'l'],
        [126, 162, $order->additional_driver, 'l'],
        [133, 162, $order->additional_driver_license, 'l'],
        // Deposit / payment / KM
        [152, 132, 'BD ' . $money($order->deposit), 'l'],
        [152, 162, __(ucfirst((string) ($order->payment_type ?? ''))), 'l'],
        [162, 118, $intf($kmOut), 'l'],
        [162, 150, $intf($kmIn), 'l'],
        [162, 178, $intf($kmUsed), 'l'],
        // Reference (top-centre)
        [16, 95, $order->reference, 'c'],
    ];
@endphp
<!doctype html>
<html @if (app()->getLocale() === 'ar') dir="ltr" @endif>
<head>
    <meta charset="utf-8">
    <title>{{ $order->reference }} · {{ __('Agreement') }}</title>
    <style>
        @page { size: A4; margin: 0; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body { background: #e5e7eb; font-family: "Courier New", monospace; }
        .page {
            position: relative;
            width: 210mm; height: 297mm;
            margin: 12px auto;
            background: #fff;
            color: #111;
            font-size: 10.5pt;
            box-shadow: 0 1px 6px rgba(0,0,0,.15);
            overflow: hidden;
        }
        .f { position: absolute; white-space: nowrap; line-height: 1; }
        .toolbar {
            position: sticky; top: 0; z-index: 50;
            display: flex; gap: 10px; align-items: center; justify-content: center;
            padding: 10px; background: #1f2937; color: #fff; font-family: system-ui, sans-serif; font-size: 13px;
        }
        .toolbar a, .toolbar button {
            font: inherit; cursor: pointer; text-decoration: none;
            border: 0; border-radius: 6px; padding: 7px 14px;
            background: #facc15; color: #111;
        }
        .toolbar a.ghost { background: transparent; color: #cbd5e1; }
        /* Calibration aids */
        .grid line { stroke: #cbd5e1; stroke-width: .2; }
        .grid .major { stroke: #93c5fd; }
        .grid text { fill: #60a5fa; font: 3px system-ui; }
        .cal .f { outline: .2mm dotted #ef4444; min-width: 6mm; min-height: 4mm; }
        @media print {
            body { background: #fff; }
            .page { margin: 0; box-shadow: none; }
            .toolbar { display: none; }
            .grid, .cal-note { display: none; }
        }
    </style>
</head>
<body @unless ($calibrate) onload="window.print()" @endunless>
    <div class="toolbar">
        <button onclick="window.print()">{{ __('Print agreement') }}</button>
        @if ($calibrate)
            <a class="ghost" href="{{ url('/app/rental/order/' . $order->id . '/agreement') }}">{{ __('Hide grid') }}</a>
            <span style="color:#cbd5e1">{{ __('Grid = 10 mm. Tell me the mm offset for any value that’s off.') }}</span>
        @else
            <a class="ghost" href="{{ url('/app/rental/order/' . $order->id . '/agreement') }}?grid=1">{{ __('Calibration grid') }}</a>
        @endif
    </div>

    <div class="page {{ $calibrate ? 'cal' : '' }}">
        @if ($calibrate)
            <svg class="grid" width="210mm" height="297mm" viewBox="0 0 210 297" style="position:absolute;inset:0">
                @for ($x = 0; $x <= 210; $x += 10)
                    <line class="{{ $x % 50 === 0 ? 'major' : '' }}" x1="{{ $x }}" y1="0" x2="{{ $x }}" y2="297"/>
                    @if ($x % 50 === 0)<text x="{{ $x + 0.5 }}" y="4">{{ $x }}</text>@endif
                @endfor
                @for ($y = 0; $y <= 297; $y += 10)
                    <line class="{{ $y % 50 === 0 ? 'major' : '' }}" x1="0" y1="{{ $y }}" x2="210" y2="{{ $y }}"/>
                    @if ($y % 50 === 0)<text x="1" y="{{ $y + 3 }}">{{ $y }}</text>@endif
                @endfor
            </svg>
        @endif

        @foreach ($F as $field)
            @php
                [$top, $left, $value, $align] = $field;
                $sp = $field[4] ?? 0;
            @endphp
            @if ($value !== null && $value !== '')
                <span class="f" style="top:{{ $top }}mm; left:{{ $left }}mm; text-align:{{ ['l'=>'left','r'=>'right','c'=>'center'][$align] ?? 'left' }};@if($sp) letter-spacing:{{ $sp }}px;@endif">{{ $value }}</span>
            @endif
        @endforeach
    </div>
</body>
</html>
