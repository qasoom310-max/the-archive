@php
    use App\Erp\Money\Currencies;
    $sales = $data['sales'];
    $stock = $data['stock'];
@endphp
<div style="font-family: Arial, Helvetica, sans-serif; color: #1f2937; max-width: 560px;">
    <h2 style="color:#111827; margin:0 0 4px;">{{ $data['venue'] }} — Daily Report</h2>
    <p style="color:#6d28d9; font-weight:bold; margin:0 0 16px;">{{ $data['period_label'] }}</p>

    <table style="width:100%; border-collapse:collapse; margin-bottom:16px;">
        <tr>
            <td style="padding:10px; background:#f5f3ff; border-radius:6px;">
                <div style="font-size:11px; color:#6b7280; text-transform:uppercase;">Revenue</div>
                <div style="font-size:18px; font-weight:bold;">{{ Currencies::format((float) $sales['revenue']) }}</div>
            </td>
            <td style="width:10px;"></td>
            <td style="padding:10px; background:#f5f3ff; border-radius:6px;">
                <div style="font-size:11px; color:#6b7280; text-transform:uppercase;">Orders</div>
                <div style="font-size:18px; font-weight:bold;">{{ $sales['orders'] }}</div>
            </td>
            <td style="width:10px;"></td>
            <td style="padding:10px; background:#f5f3ff; border-radius:6px;">
                <div style="font-size:11px; color:#6b7280; text-transform:uppercase;">Avg. order</div>
                <div style="font-size:18px; font-weight:bold;">{{ Currencies::format((float) $sales['aov']) }}</div>
            </td>
        </tr>
    </table>

    <p style="margin:0 0 16px; color:#374151;">
        Stock: {{ $stock['total'] }} products
        @if ($stock['out_count'] > 0)<span style="color:#b91c1c;">· {{ $stock['out_count'] }} out of stock</span>@endif
        @if ($stock['low_count'] > 0)<span style="color:#b45309;">· {{ $stock['low_count'] }} low</span>@endif
    </p>

    <p style="color:#6b7280; font-size:13px;">The full sales &amp; stock breakdown is attached as a PDF.</p>
</div>
