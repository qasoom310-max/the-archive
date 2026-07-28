{{-- PDF receipt — rendered server-side by DomPDF, then rasterised by
     Imagick into the PNG that WhatsApp templates accept as a HEADER:
     IMAGE. Inlined styles only (DomPDF doesn't share the Tailwind /
     Vite build), single-column layout sized for a phone-screen
     preview. Kept visually close to the on-screen receipt overlay so
     customers see the same artefact whether they're at the counter or
     opening the message later. --}}
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { size: 360px 720px; margin: 0; }
        body {
            font-family: DejaVu Sans, sans-serif;
            color: #1f2937;
            background: #fff;
            margin: 0;
            padding: 24px 28px;
            font-size: 13px;
            line-height: 1.4;
        }
        .center { text-align: center; }
        .muted { color: #6b7280; }
        .small { font-size: 11px; }
        .logo {
            display: block;
            margin: 0 auto 8px;
            max-height: 64px;
            max-width: 140px;
        }
        h1 { font-size: 16px; margin: 0 0 4px; font-weight: 700; }
        .sep {
            border-top: 1px dashed #d1d5db;
            margin: 14px 0;
        }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 3px 0; vertical-align: top; }
        td.r { text-align: right; }
        .row-bold td { font-weight: 700; }
        .row-emerald td { color: #059669; font-weight: 600; }
        .footer { margin-top: 18px; font-size: 11px; color: #9ca3af; }
    </style>
</head>
<body>
    <div class="center">
        @if ($logoPath !== null)
            {{-- DomPDF needs absolute filesystem path, not URL. --}}
            <img class="logo" src="{{ $logoPath }}" alt="{{ $companyName }}">
        @endif
        <h1>{{ $companyName }}</h1>
        <div class="muted small">{{ $orderReference }} · {{ $orderedAt }}</div>
        @if (($customerName ?? null) !== null)
            <div class="muted small">{{ $customerName }}</div>
        @endif
        @if ($customerPhone !== null)
            <div class="muted small">Phone: +{{ $customerPhone }}</div>
        @endif
        @if (($deliveryAddress ?? null) !== null)
            <div class="muted small">{{ $deliveryAddress }}</div>
        @endif
        @if (($deliveryReference ?? null) !== null)
            <div class="muted small">Delivery ref: {{ $deliveryReference }}</div>
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
                    <td class="muted" style="padding-left: 10px; font-size: 11px;">{{ $condiment }}</td>
                    <td></td>
                </tr>
            @endforeach
        @endforeach
    </table>

    <div class="sep"></div>

    <table>
        <tr><td class="muted">Subtotal</td><td class="r muted">{{ $subtotal }}</td></tr>
        <tr><td class="muted">Tax</td><td class="r muted">{{ $taxTotal }}</td></tr>
        @if ($customerDiscount !== null)
            <tr class="row-emerald"><td>Customer discount ({{ $customerDiscountPercent }}%)</td><td class="r">−{{ $customerDiscount }}</td></tr>
        @endif
        @if ($deliveryCharge !== null)
            <tr><td class="muted">Delivery</td><td class="r muted">{{ $deliveryCharge }}</td></tr>
        @endif
        <tr class="row-bold"><td>Total</td><td class="r">{{ $total }}</td></tr>
        @foreach ($payments as $p)
            <tr><td class="muted">{{ $p['method'] }}</td><td class="r muted">{{ $p['amount'] }}</td></tr>
        @endforeach
        @if ($changeDue !== null)
            <tr class="row-emerald"><td>Change</td><td class="r">{{ $changeDue }}</td></tr>
        @endif
    </table>

    <p class="center footer">Thank you!</p>
</body>
</html>
