@php
    $num = fn ($v) => rtrim(rtrim(number_format((float) $v, 3), '0'), '.');
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { color: #1f2937; font-size: 11px; }
        h1 { font-size: 18px; margin: 0 0 2px; }
        .muted { color: #6b7280; font-size: 10px; margin: 0 0 10px; }
        .chips span { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 10px; margin-right: 6px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th { text-align: left; font-size: 9px; text-transform: uppercase; letter-spacing: .04em; color: #6b7280; border-bottom: 1px solid #d1d5db; padding: 6px 6px; }
        td { padding: 6px 6px; border-bottom: 1px solid #eef0f2; }
        .r { text-align: right; }
        .out { color: #b91c1c; font-weight: bold; }
        .low { color: #b45309; font-weight: bold; }
        .empty { text-align: center; color: #9ca3af; padding: 24px; }
    </style>
</head>
<body>
    <h1>{{ __('Reorder Report') }}</h1>
    <p class="muted">
        {{ __('Purchasable items at or below their minimum stock. Hand this to the buying team.') }}<br>
        {{ __('To reorder') }}: {{ $summary['total'] }} ·
        {{ __('Low stock') }}: {{ $summary['low'] }} ·
        {{ __('Out of stock') }}: {{ $summary['out'] }} ·
        {{ $generatedAt->isoFormat('YYYY-MM-DD HH:mm') }}
    </p>

    <table>
        <thead>
            <tr>
                <th>{{ __('Item') }}</th>
                <th>{{ __('Type') }}</th>
                <th class="r">{{ __('Current stock') }}</th>
                <th class="r">{{ __('Minimum') }}</th>
                <th>{{ __('Vendor') }}</th>
                <th>{{ __('Phone') }}</th>
                <th>{{ __('Status') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                @php
                    $item = $row->item;
                    $unit = $item->unit !== '' ? ' ' . $item->unit : '';
                    $min = $item->reorderPoint ?? $threshold;
                    $typeText = $item->isCondiment() ? __('Condiment') : ($item->isIngredient() ? __('Ingredient') : __('Product'));
                @endphp
                <tr>
                    <td>{{ $item->name }}</td>
                    <td>{{ $typeText }}</td>
                    <td class="r">{{ $num($item->stock) }}{{ $unit }}</td>
                    <td class="r">{{ $num($min) }}{{ $unit }}</td>
                    <td>{{ $row->vendorName ?? '—' }}</td>
                    <td>{{ $row->vendorPhone ?? '—' }}</td>
                    <td class="{{ $item->status === 'out' ? 'out' : 'low' }}">
                        {{ $item->status === 'out' ? __('Out of stock') : __('Low stock') }}
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="empty">{{ __('Nothing to reorder — every purchasable item is above its minimum.') }}</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
