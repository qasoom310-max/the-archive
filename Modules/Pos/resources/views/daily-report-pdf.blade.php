@php
    use App\Erp\Money\Currencies;
    $money = fn ($v) => Currencies::format((float) $v);
    $sales = $data['sales'];
    $stock = $data['stock'];
@endphp
{{-- Professional daily report — server-rendered by DomPDF (inline CSS only,
     no Tailwind/Vite). A4, single document attached to the daily email. --}}
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 28px 32px; }
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; color: #1f2937; font-size: 12px; line-height: 1.45; margin: 0; }
        .head { border-bottom: 3px solid #6d28d9; padding-bottom: 10px; margin-bottom: 16px; }
        .head h1 { margin: 0; font-size: 22px; font-weight: 700; color: #111827; }
        .head .sub { color: #6b7280; font-size: 12px; margin-top: 2px; }
        .head .period { color: #6d28d9; font-weight: 600; font-size: 12px; margin-top: 4px; }
        h2 { font-size: 13px; font-weight: 700; color: #374151; text-transform: uppercase; letter-spacing: .04em; margin: 22px 0 8px; }
        .kpis { width: 100%; border-collapse: separate; border-spacing: 8px 0; margin: 0 -8px; }
        .kpis td { width: 33.33%; background: #f5f3ff; border: 1px solid #ede9fe; border-radius: 8px; padding: 12px 14px; vertical-align: top; }
        .kpis .label { color: #6b7280; font-size: 10px; text-transform: uppercase; letter-spacing: .05em; }
        .kpis .value { color: #111827; font-size: 19px; font-weight: 700; margin-top: 3px; }
        table.data { width: 100%; border-collapse: collapse; margin-top: 4px; }
        table.data th { text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: .04em; color: #6b7280; border-bottom: 1.5px solid #e5e7eb; padding: 6px 8px; }
        table.data td { padding: 6px 8px; border-bottom: 1px solid #f3f4f6; }
        table.data td.r, table.data th.r { text-align: right; }
        .muted { color: #6b7280; }
        .pill { display: inline-block; padding: 1px 7px; border-radius: 999px; font-size: 10px; font-weight: 700; }
        .pill-out { background: #fee2e2; color: #b91c1c; }
        .pill-low { background: #fef3c7; color: #b45309; }
        tr.row-out td { background: #fef2f2; }
        tr.row-low td { background: #fffbeb; }
        .summary-line { margin-top: 6px; color: #6b7280; font-size: 11px; }
        .foot { margin-top: 22px; border-top: 1px solid #e5e7eb; padding-top: 8px; color: #9ca3af; font-size: 10px; text-align: center; }
    </style>
</head>
<body>
    <div class="head">
        <h1>{{ $data['venue'] }}</h1>
        <div class="sub">Daily Sales &amp; Stock Report</div>
        <div class="period">{{ $data['period_label'] }}</div>
    </div>

    <h2>Sales summary</h2>
    <table class="kpis">
        <tr>
            <td><div class="label">Revenue</div><div class="value">{{ $money($sales['revenue']) }}</div></td>
            <td><div class="label">Orders</div><div class="value">{{ $sales['orders'] }}</div></td>
            <td><div class="label">Avg. order</div><div class="value">{{ $money($sales['aov']) }}</div></td>
        </tr>
    </table>
    <div class="summary-line">
        Tax collected: <strong>{{ $money($sales['tax_total']) }}</strong>
        &nbsp;·&nbsp; Customer discounts: <strong>{{ $money($sales['discount_total']) }}</strong>
    </div>

    <h2>Payments by method</h2>
    @if (count($sales['payments']) > 0)
        <table class="data">
            <thead><tr><th>Method</th><th class="r">Amount</th></tr></thead>
            <tbody>
                @foreach ($sales['payments'] as $method => $amount)
                    <tr><td>{{ $method }}</td><td class="r">{{ $money($amount) }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @else
        <div class="muted">No payments in this period.</div>
    @endif

    <h2>Top products</h2>
    @if (count($sales['top_products']) > 0)
        <table class="data">
            <thead><tr><th>Product</th><th class="r">Qty</th><th class="r">Sales</th></tr></thead>
            <tbody>
                @foreach ($sales['top_products'] as $p)
                    <tr>
                        <td>{{ $p['name'] }}</td>
                        <td class="r">{{ rtrim(rtrim(number_format($p['qty'], 3), '0'), '.') }}</td>
                        <td class="r">{{ $money($p['total']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <div class="muted">No products sold in this period.</div>
    @endif

    <h2>Stock report
        <span class="muted" style="font-weight:400; text-transform:none; letter-spacing:0;">
            — {{ $stock['total'] }} products
            @if ($stock['out_count'] > 0) · <span style="color:#b91c1c;">{{ $stock['out_count'] }} out of stock</span> @endif
            @if ($stock['low_count'] > 0) · <span style="color:#b45309;">{{ $stock['low_count'] }} low</span> @endif
        </span>
    </h2>
    @if (count($stock['rows']) > 0)
        <table class="data">
            <thead><tr><th>Product</th><th class="r">On hand</th><th>Status</th></tr></thead>
            <tbody>
                @foreach ($stock['rows'] as $row)
                    <tr class="{{ $row['out'] ? 'row-out' : ($row['low'] ? 'row-low' : '') }}">
                        <td>{{ $row['name'] }}</td>
                        <td class="r">{{ rtrim(rtrim(number_format($row['stock'], 3), '0'), '.') }}</td>
                        <td>
                            @if ($row['out'])<span class="pill pill-out">OUT OF STOCK</span>
                            @elseif ($row['low'])<span class="pill pill-low">LOW</span>
                            @else <span class="muted">OK</span>@endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <div class="muted">No active products.</div>
    @endif

    <div class="foot">Generated {{ $data['generated_at']->isoFormat('MMM D, YYYY h:mm A') }} · {{ $data['venue'] }}</div>
</body>
</html>
