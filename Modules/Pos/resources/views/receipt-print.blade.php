{{-- Browser-printable receipt — same data shape as the WhatsApp PNG
     (pos::receipt-pdf), tuned for an on-screen tab that auto-opens the
     print dialog. Standalone HTML (no app chrome) so the slip prints
     clean on a thermal / A4 printer. --}}
<!doctype html>
<html @if(app()->getLocale() === 'ar') dir="rtl" @endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $orderReference }}</title>
    <style>
        /* Sized for a till printer: big, dark type that fills the paper roll.
           Grey text prints faint on thermal paper, so "muted" stays near-black. */
        @page { margin: 3mm; }
        body {
            font-family: system-ui, -apple-system, "Segoe UI", Tahoma, sans-serif;
            color: #000;
            background: #f3f4f6;
            margin: 0;
            padding: 24px;
            font-size: 17px;
            line-height: 1.45;
        }
        .slip {
            max-width: 420px;
            margin: 0 auto;
            background: #fff;
            padding: 24px 22px;
            border-radius: 10px;
            box-shadow: 0 1px 4px rgba(0,0,0,.08);
        }
        .center { text-align: center; }
        .muted { color: #222; }
        .small { font-size: 15px; }
        .logo { display: block; margin: 0 auto 10px; max-height: 110px; max-width: 220px; }
        h1 { font-size: 24px; margin: 0 0 6px; font-weight: 800; }
        .sep { border-top: 2px dashed #000; margin: 14px 0; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 5px 0; vertical-align: top; }
        td.r { text-align: end; white-space: nowrap; padding-inline-start: 10px; }
        .lines td { font-weight: 600; }
        .row-bold td { font-weight: 800; font-size: 21px; padding-top: 8px; }
        .row-emerald td { color: #000; font-weight: 700; }
        .footer { margin-top: 18px; font-size: 16px; font-weight: 600; }
        .actions { max-width: 420px; margin: 12px auto 0; text-align: center; }
        button {
            font: inherit; cursor: pointer; border: 0; border-radius: 8px;
            background: #1f2937; color: #fff; padding: 8px 18px;
        }
        @media print {
            body { background: #fff; padding: 0; }
            /* Fill the whole paper width rather than a box in the middle of it. */
            .slip { max-width: none; width: auto; padding: 0; box-shadow: none; border-radius: 0; }
            .actions { display: none; }
        }
    </style>
</head>
<body onload="window.print()">
    <div class="slip">
        <div class="center">
            @if (($logoUrl ?? null) !== null)
                <img class="logo" src="{{ $logoUrl }}" alt="">
            @endif
            <h1>{{ $companyName }}</h1>
            <div class="muted small">{{ $orderReference }} · {{ $orderedAt }}</div>
            @if (($customerName ?? null) !== null)
                <div class="muted small">{{ $customerName }}</div>
            @endif
            @if ($customerPhone !== null)
                <div class="muted small">{{ __('Phone:') }} +{{ $customerPhone }}</div>
            @endif
            @if (($deliveryAddress ?? null) !== null)
                <div class="muted small">{{ $deliveryAddress }}</div>
            @endif
            @if (($deliveryReference ?? null) !== null)
                <div class="muted small">{{ __('Delivery ref') }}: {{ $deliveryReference }}</div>
            @endif
        </div>

        <div class="sep"></div>

        <table class="lines">
            @foreach ($lines as $line)
                <tr>
                    <td>{{ $line['qty'] }}× {{ $line['name'] }}</td>
                    <td class="r">{{ $line['total'] }}</td>
                </tr>
                @foreach ($line['condiments'] ?? [] as $condiment)
                    <tr>
                        <td class="muted" style="padding-inline-start: 14px; font-size: 15px; font-weight: 400;">{{ $condiment }}</td>
                        <td></td>
                    </tr>
                @endforeach
            @endforeach
        </table>

        <div class="sep"></div>

        <table>
            <tr><td class="muted">{{ __('Subtotal') }}</td><td class="r muted">{{ $subtotal }}</td></tr>
            <tr><td class="muted">{{ __('Tax') }}</td><td class="r muted">{{ $taxTotal }}</td></tr>
            @if ($customerDiscount !== null)
                <tr class="row-emerald"><td>{{ __('Customer discount') }} ({{ $customerDiscountPercent }}%)</td><td class="r">−{{ $customerDiscount }}</td></tr>
            @endif
            @if ($deliveryCharge !== null)
                <tr><td class="muted">{{ __('Delivery') }}</td><td class="r muted">{{ $deliveryCharge }}</td></tr>
            @endif
            <tr class="row-bold"><td>{{ __('Total') }}</td><td class="r">{{ $total }}</td></tr>
            @foreach ($payments as $p)
                <tr><td class="muted">{{ $p['method'] }}</td><td class="r muted">{{ $p['amount'] }}</td></tr>
            @endforeach
            @if ($changeDue !== null)
                <tr class="row-emerald"><td>{{ __('Change') }}</td><td class="r">{{ $changeDue }}</td></tr>
            @endif
        </table>

        <p class="center footer">{{ __('Thank you!') }}</p>
    </div>

    <div class="actions">
        <button type="button" onclick="window.print()">{{ __('Print') }}</button>
    </div>
</body>
</html>
