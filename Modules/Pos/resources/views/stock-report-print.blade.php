{{-- Browser-printable Stock Report (standalone HTML, auto-print). --}}
<!doctype html>
<html @if(app()->getLocale() === 'ar') dir="rtl" @endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Stock Report') }} — {{ $company }}</title>
    <style>
        body { font-family: system-ui, -apple-system, "Segoe UI", sans-serif; color: #1f2937; margin: 24px; font-size: 13px; }
        h1 { font-size: 18px; margin: 0 0 2px; }
        .muted { color: #6b7280; font-size: 12px; }
        .summary { margin: 14px 0; font-size: 13px; }
        .summary span { margin-inline-end: 16px; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { padding: 6px 8px; border-bottom: 1px solid #e5e7eb; text-align: start; }
        td.r, th.r { text-align: end; }
        thead th { background: #f9fafb; text-transform: uppercase; font-size: 11px; color: #6b7280; }
        .badge { padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 600; }
        .in { background: #d1fae5; color: #047857; }
        .low { background: #fef3c7; color: #b45309; }
        .out { background: #fee2e2; color: #b91c1c; }
        .actions { margin: 14px 0; }
        button { font: inherit; cursor: pointer; border: 0; border-radius: 8px; background: #1f2937; color: #fff; padding: 8px 18px; }
        @media print { body { margin: 0; } .actions { display: none; } }
    </style>
</head>
<body onload="window.print()">
    <h1>{{ __('Stock Report') }}</h1>
    <div class="muted">{{ $company }} · {{ $generatedAt }}</div>

    <div class="summary">
        <span><strong>{{ $summary['total'] }}</strong> {{ __('All') }}</span>
        <span style="color:#047857"><strong>{{ $summary['in'] }}</strong> {{ __('In stock') }}</span>
        <span style="color:#b45309"><strong>{{ $summary['low'] }}</strong> {{ __('Low stock') }}</span>
        <span style="color:#b91c1c"><strong>{{ $summary['out'] }}</strong> {{ __('Out of stock') }}</span>
        <span>{{ __('Inventory value') }}: <strong>{{ $summaryValue }}</strong></span>
    </div>

    <table>
        <thead>
            <tr>
                <th>{{ __('Product') }}</th>
                <th>{{ __('Category') }}</th>
                <th class="r">{{ __('On hand') }}</th>
                <th class="r">{{ __('Value') }}</th>
                <th class="r">{{ __('Status') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                @php $cls = ['In stock' => 'in', 'Low stock' => 'low', 'Out of stock' => 'out'][$row['status']] ?? 'in'; @endphp
                <tr>
                    <td>{{ $row['name'] }}</td>
                    <td>{{ $row['category'] }}</td>
                    <td class="r">{{ $row['stock'] }}</td>
                    <td class="r">{{ $row['value'] }}</td>
                    <td class="r"><span class="badge {{ $cls }}">{{ __($row['status']) }}</span></td>
                </tr>
            @empty
                <tr><td colspan="5" class="muted" style="text-align:center;padding:24px;">{{ __('No products found.') }}</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="actions"><button type="button" onclick="window.print()">{{ __('Print') }}</button></div>
</body>
</html>
