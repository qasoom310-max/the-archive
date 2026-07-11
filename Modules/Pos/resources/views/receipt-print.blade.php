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
        body {
            font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
            color: #1f2937;
            background: #f3f4f6;
            margin: 0;
            padding: 24px;
            font-size: 13px;
            line-height: 1.45;
        }
        .slip {
            max-width: 340px;
            margin: 0 auto;
            background: #fff;
            padding: 24px 28px;
            border-radius: 10px;
            box-shadow: 0 1px 4px rgba(0,0,0,.08);
        }
        .center { text-align: center; }
        .muted { color: #6b7280; }
        .small { font-size: 11px; }
        .logo { display: block; margin: 0 auto 8px; max-height: 64px; max-width: 140px; }
        h1 { font-size: 16px; margin: 0 0 4px; font-weight: 700; }
        .sep { border-top: 1px dashed #d1d5db; margin: 14px 0; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 3px 0; vertical-align: top; }
        td.r { text-align: end; }
        .row-bold td { font-weight: 700; }
        .row-emerald td { color: #059669; font-weight: 600; }
        .footer { margin-top: 18px; font-size: 11px; color: #9ca3af; }
        .actions { max-width: 340px; margin: 12px auto 0; text-align: center; }
        button {
            font: inherit; cursor: pointer; border: 0; border-radius: 8px;
            background: #1f2937; color: #fff; padding: 8px 18px;
        }
        @media print {
            body { background: #fff; padding: 0; }
            .slip { box-shadow: none; border-radius: 0; }
            .actions { display: none; }
        }
    </style>
</head>
<body onload="window.print()">
    <div class="slip">
        <div class="center">
            @if ($logoPath !== null)
                <img class="logo" src="{{ $logoPath }}" alt="{{ $companyName }}">
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
        </div>

        <div class="sep"></div>

        <table>
            @foreach ($lines as $line)
                <tr>
                    <td>{{ $line['qty'] }}× {{ $line['name'] }}</td>
                    <td class="r">{{ $line['total'] }}</td>
                </tr>
                @foreach ($line['condiments'] ?? [] as $condiment)
                    <tr>
                        <td class="muted" style="padding-inline-start: 10px; font-size: 11px;">{{ $condiment }}</td>
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
            @if (($deliveryFee ?? null) !== null)
                <tr><td class="muted">{{ __('Delivery fee') }}</td><td class="r muted">{{ $deliveryFee }}</td></tr>
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
